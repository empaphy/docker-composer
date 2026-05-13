<?php

/**
 * @noinspection PhpDocMissingThrowsInspection
 * @noinspection PhpUnhandledExceptionInspection
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

#[CoversNothing]
class DockerComposerIntegrationTest extends TestCase
{
    /**
     * @var list<string>
     */
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
        ]);
        $this->installProject($projectDirectory);

        $this->runCommand(['docker', 'compose', 'down', '--volumes', '--remove-orphans'], $projectDirectory);
        $this->runCommand(['composer', 'run-script', 'mark'], $projectDirectory);
        self::assertSame('1', trim((string) file_get_contents($projectDirectory . '/result.txt')));

        @unlink($projectDirectory . '/lifecycle.txt');
        $this->runCommand(['composer', 'dump-autoload'], $projectDirectory);
        self::assertSame('1', trim((string) file_get_contents($projectDirectory . '/lifecycle.txt')));
    }

    public function testInstallCommandRedirectsWhenPluginIsAlreadyInstalled(): void
    {
        $projectDirectory = $this->createProject([
            'service' => 'php',
            'mode' => 'exec',
            'compose-files' => 'docker-compose.yaml',
            'workdir' => '/usr/src/app',
        ]);
        $this->installProject($projectDirectory);

        $result = $this->runCommand(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], $projectDirectory);

        self::assertStringContainsString('Running composer install in Docker Compose service php.', $result['stderr']);
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

    public function testLaravelAutodiscoveryAndConsoleRedirection(): void
    {
        $projectDirectory = $this->createLaravelProject();
        $this->installProject($projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => '0']);

        $packages = require $projectDirectory . '/bootstrap/cache/packages.php';
        self::assertContains('empaphy\\docker_composer\\Laravel\\ServiceProvider', $packages['empaphy/docker-composer']['providers'] ?? []);

        $this->runCommand(['php', 'artisan', 'vendor:publish', '--tag=docker-composer-config', '--force'], $projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => '0']);
        self::assertFileExists($projectDirectory . '/config/docker_composer.php');
        $this->writeLaravelDockerComposerConfig($projectDirectory);

        $this->runCommand(['docker', 'compose', 'down', '--volumes', '--remove-orphans'], $projectDirectory);

        $result = $this->runCommand(['php', 'artisan', 'mark'], $projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => 'true']);
        self::assertSame('1', trim((string) file_get_contents($projectDirectory . '/result.txt')), $result['stdout'] . $result['stderr']);

        $this->runCommand(['php', 'artisan', 'class-map'], $projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => 'true']);
        self::assertSame('mapped', trim((string) file_get_contents($projectDirectory . '/class.txt')));

        $this->runCommand(['php', 'scripts/bootstrap.php'], $projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => 'true']);
        self::assertSame('mapped', trim((string) file_get_contents($projectDirectory . '/script.txt')));

        $this->runCommand(['php', 'artisan', 'host-only'], $projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => 'true']);
        self::assertSame('host', trim((string) file_get_contents($projectDirectory . '/host.txt')));

        @unlink($projectDirectory . '/result.txt');
        $this->runCommand(['php', 'artisan', 'mark'], $projectDirectory, ['DOCKER_COMPOSER_LARAVEL' => '0']);
        self::assertSame('host', trim((string) file_get_contents($projectDirectory . '/result.txt')));
    }

    /**
     * @param  array<string, mixed>             $dockerComposerConfig
     * @param  list<array<string, mixed>>|null  $repositories
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
     * @return list<string>
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

    /**
     * @param array<string, string> $environment
     */
    private function installProject(string $projectDirectory, array $environment = []): void
    {
        $this->runCommand(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], $projectDirectory, $environment);
    }

    private function createLaravelProject(): string
    {
        $projectDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'docker-composer-laravel-integration-'
            . bin2hex(random_bytes(8));
        if (! mkdir($projectDirectory, 0777, true) && ! is_dir($projectDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create integration project directory "%s".', $projectDirectory));
        }

        $this->projectDirectories[] = $projectDirectory;
        foreach ([
            'app/Console/Commands',
            'app/Exceptions',
            'app/Console',
            'bootstrap/cache',
            'config',
            'scripts',
        ] as $directory) {
            $path = $projectDirectory . '/' . $directory;
            if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s".', $path));
            }
        }

        $this->writeJson($projectDirectory . '/composer.json', [
            'name' => 'empaphy/docker-composer-laravel-integration',
            'description' => 'Temporary docker-composer Laravel integration fixture.',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => [[
                'type' => 'path',
                'url' => dirname(__DIR__, 2),
                'options' => ['symlink' => false],
            ]],
            'require' => [
                'laravel/framework' => '^12.0',
                'empaphy/docker-composer' => '*',
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                ],
            ],
            'config' => [
                'allow-plugins' => [
                    'empaphy/docker-composer' => true,
                ],
            ],
            'scripts' => [
                'post-autoload-dump' => [
                    'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                    '@php artisan package:discover --ansi',
                ],
            ],
        ]);

        file_put_contents($projectDirectory . '/artisan', <<<'PHP'
#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

define('LARAVEL_START', microtime(true));

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$input = new ArgvInput();
$status = $kernel->handle($input, new ConsoleOutput());
$kernel->terminate($input, $status);

exit($status);
PHP);
        chmod($projectDirectory . '/artisan', 0755);

        file_put_contents($projectDirectory . '/bootstrap/app.php', <<<'PHP'
<?php

use App\Console\Kernel;
use App\Exceptions\Handler;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;

$app = new Application(dirname(__DIR__));
$app->singleton(KernelContract::class, Kernel::class);
$app->singleton(ExceptionHandler::class, Handler::class);

return $app;
PHP);

        file_put_contents($projectDirectory . '/config/app.php', <<<'PHP'
<?php

use Illuminate\Support\ServiceProvider;

return [
    'name' => 'Docker Composer Test',
    'env' => 'testing',
    'debug' => true,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'cipher' => 'AES-256-CBC',
    'providers' => ServiceProvider::defaultProviders()->toArray(),
];
PHP);

        file_put_contents($projectDirectory . '/app/Console/Kernel.php', <<<'PHP'
<?php

namespace App\Console;

use App\Console\Commands\ClassMappedCommand;
use App\Console\Commands\HostOnlyCommand;
use App\Console\Commands\MarkCommand;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * @var list<class-string>
     */
    protected $commands = [
        MarkCommand::class,
        ClassMappedCommand::class,
        HostOnlyCommand::class,
    ];
}
PHP);

        file_put_contents($projectDirectory . '/app/Exceptions/Handler.php', <<<'PHP'
<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
}
PHP);

        file_put_contents($projectDirectory . '/app/Console/Commands/MarkCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarkCommand extends Command
{
    protected $signature = 'mark';

    protected $description = 'Mark the current execution environment.';

    public function handle(): int
    {
        file_put_contents(base_path('result.txt'), getenv('DOCKER_COMPOSER_TEST_MARK') ?: (getenv('DOCKER_COMPOSER_INSIDE') ?: 'host'));

        return self::SUCCESS;
    }
}
PHP);

        file_put_contents($projectDirectory . '/app/Console/Commands/ClassMappedCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ClassMappedCommand extends Command
{
    protected $signature = 'class-map';

    protected $description = 'Mark class mapped execution.';

    public function handle(): int
    {
        file_put_contents(base_path('class.txt'), getenv('DOCKER_COMPOSER_TEST_MARK') ?: (getenv('DOCKER_COMPOSER_INSIDE') ?: 'host'));

        return self::SUCCESS;
    }
}
PHP);

        file_put_contents($projectDirectory . '/app/Console/Commands/HostOnlyCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HostOnlyCommand extends Command
{
    protected $signature = 'host-only';

    protected $description = 'Mark host-only execution.';

    public function handle(): int
    {
        file_put_contents(base_path('host.txt'), getenv('DOCKER_COMPOSER_INSIDE') ?: 'host');

        return self::SUCCESS;
    }
}
PHP);

        file_put_contents($projectDirectory . '/scripts/bootstrap.php', <<<'PHP'
#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents(__DIR__ . '/../script.txt', getenv('DOCKER_COMPOSER_TEST_MARK') ?: (getenv('DOCKER_COMPOSER_INSIDE') ?: 'host'));
PHP);
        chmod($projectDirectory . '/scripts/bootstrap.php', 0755);

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
      DOCKER_COMPOSER_TEST_MARK: mapped
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
YAML, $this->getComposerImage(), $this->getComposerImage()));

        return $projectDirectory;
    }

    private function writeLaravelDockerComposerConfig(string $projectDirectory): void
    {
        file_put_contents($projectDirectory . '/config/docker_composer.php', <<<'PHP'
<?php

return [
    'enabled' => env('DOCKER_COMPOSER_LARAVEL', false),
    'service' => 'php',
    'mode' => 'exec',
    'compose_files' => 'docker-compose.yaml',
    'workdir' => '/usr/src/app',
    'exclude' => ['host-only'],
    'service_mapping' => [
        'php_tools' => [
            App\Console\Commands\ClassMappedCommand::class,
            ':scripts/bootstrap.php',
        ],
    ],
];
PHP);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException(sprintf('Unable to encode "%s".', $path));
        }

        file_put_contents($path, $encoded . PHP_EOL);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return array{stdout: string, stderr: string, exit-code: int}
     */
    private function runCommand(array $command, string $workingDirectory, array $environment = [], bool $failOnError = true): array
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

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit-code' => $exitCode,
        ];
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
