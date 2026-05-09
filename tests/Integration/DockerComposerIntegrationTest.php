<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

#[CoversNothing]
class DockerComposerIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $projectDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->projectDirectories as $projectDirectory) {
            $this->runCommand(['docker', 'compose', 'down', '--volumes', '--remove-orphans'], $projectDirectory, [], false);
            $this->removeDirectory($projectDirectory);
        }

        $this->projectDirectories = [];
    }

    public function testExecModeRedirectsCustomAndLifecycleScriptsWithAutoUp(): void
    {
        $projectDirectory = $this->createProject([
            'service' => 'php',
            'mode' => 'exec',
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
        $this->installProject($projectDirectory);

        $this->runCommand(['docker', 'compose', 'down', '--volumes', '--remove-orphans'], $projectDirectory);
        $this->runCommand(['composer', 'run-script', 'mark'], $projectDirectory);
        self::assertSame('1', trim((string) file_get_contents($projectDirectory . '/result.txt')));

        @unlink($projectDirectory . '/lifecycle.txt');
        $this->runCommand(['composer', 'dump-autoload'], $projectDirectory);
        self::assertSame('1', trim((string) file_get_contents($projectDirectory . '/lifecycle.txt')));
    }

    public function testServiceMappingOverrideRedirectsToConfiguredService(): void
    {
        $projectDirectory = $this->createProject([
            'service' => 'php',
            'service-mapping' => [
                'php_tools' => 'mark',
            ],
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
        $this->installProject($projectDirectory);

        $this->runCommand(['composer', 'run-script', 'mark'], $projectDirectory);

        self::assertSame('override', trim((string) file_get_contents($projectDirectory . '/result.txt')));
    }

    public function testRunModeBypassMissingConfigAndInsideContainerBehavior(): void
    {
        $runProjectDirectory = $this->createProject([
            'service' => 'php',
            'mode' => 'run',
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
        $this->installProject($runProjectDirectory);

        $this->runCommand(['composer', 'run-script', 'mark'], $runProjectDirectory);
        self::assertSame('1', trim((string) file_get_contents($runProjectDirectory . '/result.txt')));

        @unlink($runProjectDirectory . '/result.txt');
        $this->runCommand(['composer', 'run-script', 'mark'], $runProjectDirectory, ['DOCKER_COMPOSER_DISABLE' => '1']);
        self::assertSame('host', trim((string) file_get_contents($runProjectDirectory . '/result.txt')));

        @unlink($runProjectDirectory . '/result.txt');
        $this->runCommand([
            'docker',
            'compose',
            'run',
            '--rm',
            '-T',
            '--workdir',
            '/usr/src/app',
            '--env',
            'DOCKER_COMPOSER_INSIDE=1',
            'php',
            'composer',
            'run-script',
            'mark',
        ], $runProjectDirectory);
        self::assertSame('1', trim((string) file_get_contents($runProjectDirectory . '/result.txt')));

        $missingConfigProjectDirectory = $this->createProject([]);
        $this->installProject($missingConfigProjectDirectory);

        $this->runCommand(['composer', 'run-script', 'mark'], $missingConfigProjectDirectory);
        self::assertSame('host', trim((string) file_get_contents($missingConfigProjectDirectory . '/result.txt')));
    }

    /**
     * @param array<string, mixed> $dockerComposerConfig
     * @param list<array<string, mixed>>|null $repositories
     */
    private function createProject(array $dockerComposerConfig, ?array $repositories = null, string $requireVersion = '*'): string
    {
        $projectDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'docker-composer-integration-'
            . bin2hex(random_bytes(8));
        if (! mkdir($projectDirectory, 0777, true) && ! is_dir($projectDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create integration project directory "%s".', $projectDirectory));
        }

        $this->projectDirectories[] = $projectDirectory;

        $composerJson = [
            'name' => 'empaphy/docker-composer-integration',
            'description' => 'Temporary docker-composer integration fixture.',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => $repositories ?? [[
                'type' => 'path',
                'url' => dirname(__DIR__, 2),
                'options' => ['symlink' => false],
            ]],
            'require' => [
                'empaphy/docker-composer' => $requireVersion,
            ],
            'config' => [
                'allow-plugins' => [
                    'empaphy/docker-composer' => true,
                ],
            ],
            'scripts' => [
                'mark' => '@php -r "file_put_contents(\'result.txt\', getenv(\'DOCKER_COMPOSER_TEST_MARK\') ?: (getenv(\'DOCKER_COMPOSER_INSIDE\') ?: \'host\'));"',
                'post-autoload-dump' => '@php -r "file_put_contents(\'lifecycle.txt\', getenv(\'DOCKER_COMPOSER_INSIDE\') ?: \'host\');"',
            ],
            'extra' => [
                'docker-composer' => $dockerComposerConfig,
            ],
        ];

        $encodedComposerJson = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedComposerJson === false) {
            throw new \RuntimeException('Unable to encode integration composer.json.');
        }

        file_put_contents($projectDirectory . '/composer.json', $encodedComposerJson . PHP_EOL);
        file_put_contents($projectDirectory . '/docker-compose.yaml', sprintf(<<<'YAML'
services:
  php:
    image: %s
    command: ['sleep', 'infinity']
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
  php_tools:
    image: %s
    command: ['sleep', 'infinity']
    environment:
      DOCKER_COMPOSER_TEST_MARK: override
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
YAML, $this->getComposerImage(), $this->getComposerImage()));

        return $projectDirectory;
    }

    /**
     * @param list<array<string, mixed>> $repositories
     */
    protected function updateProjectRepositories(string $projectDirectory, array $repositories): void
    {
        $composerJsonPath = $projectDirectory . '/composer.json';
        $composerJson = json_decode((string) file_get_contents($composerJsonPath), true);
        if (! is_array($composerJson)) {
            throw new \RuntimeException(sprintf('Unable to decode "%s".', $composerJsonPath));
        }

        $composerJson['repositories'] = $repositories;

        $encodedComposerJson = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedComposerJson === false) {
            throw new \RuntimeException(sprintf('Unable to encode "%s".', $composerJsonPath));
        }

        file_put_contents($composerJsonPath, $encodedComposerJson . PHP_EOL);
    }

    private function getComposerImage(): string
    {
        $composerVersion = getenv('DOCKER_COMPOSER_TEST_COMPOSER_VERSION');
        if ($composerVersion === false || $composerVersion === '' || $composerVersion === 'v2') {
            return 'composer:2';
        }

        return 'composer:' . $composerVersion;
    }

    /**
     * Gets a Composer require command for the active integration Composer version.
     *
     * @param  string  $package
     *   The package constraint to require.
     *
     * @return list<string>
     *   Returns a Composer require command compatible with the active version.
     */
    protected function getRequireCommand(string $package): array
    {
        $command = ['composer', 'require', $package, '--no-interaction', '--no-progress'];
        $composerVersion = getenv('DOCKER_COMPOSER_TEST_COMPOSER_VERSION');
        if ($composerVersion !== false && $composerVersion !== 'v2') {
            return $command;
        }

        array_splice($command, 2, 0, '-m');

        return $command;
    }

    private function installProject(string $projectDirectory): void
    {
        $this->runCommand(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], $projectDirectory);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function runCommand(array $command, string $workingDirectory, array $environment = [], bool $failOnError = true): void
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $processEnvironment = array_merge(getenv() ?: [], [
            'COMPOSER_CACHE_DIR' => $workingDirectory . '/.composer-cache',
            'COMPOSER_NO_INTERACTION' => '1',
        ], $environment);

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $processEnvironment);
        if (! is_resource($process)) {
            throw new \RuntimeException(sprintf('Unable to start command: %s', implode(' ', $command)));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($failOnError && $exitCode !== 0) {
            self::fail(sprintf(
                "Command failed with exit code %d:\n%s\n\nSTDOUT:\n%s\n\nSTDERR:\n%s",
                $exitCode,
                implode(' ', $command),
                $stdout,
                $stderr,
            ));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && ! $fileInfo->isLink()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($directory);
    }
}
