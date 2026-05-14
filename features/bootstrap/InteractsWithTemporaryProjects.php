<?php

/**
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Shares temporary project lifecycle and command helpers across Behat contexts.
 */
trait InteractsWithTemporaryProjects
{
    /**
     * Temporary project directories created during the current scenario.
     *
     * @var list<string>
     */
    private array $projectDirectories = [];

    /**
     * The temporary project used by the current step.
     */
    protected ?string $projectDirectory = null;

    /**
     * The process result captured from the latest command.
     *
     * @var array{stdout: string, stderr: string, exitCode: int}|null
     */
    protected ?array $lastCommandResult = null;

    /**
     * Dependency resolution mode selected by the active Behat profile.
     */
    private string $dependencyResolutionMode = 'latest';

    public function __construct(string $dependencyResolutionMode = 'latest')
    {
        $this->setDependencyResolutionMode($dependencyResolutionMode);
    }

    #[AfterScenario]
    public function cleanupProjects(): void
    {
        foreach ($this->projectDirectories as $projectDirectory) {
            $this->runCommand(['docker', 'compose', 'down', '--volumes', '--remove-orphans'], $projectDirectory, [], false);
            $this->removeDirectory($projectDirectory);
        }

        $this->projectDirectories = [];
        $this->projectDirectory = null;
        $this->lastCommandResult = null;
    }

    #[When('I run :command in the project')]
    public function runCommandInProject(string $command): void
    {
        $this->lastCommandResult = $this->runShellCommand(
            $command,
            $this->getProjectDirectory(),
            $this->explicitProjectCommandEnvironment(),
        );
    }

    #[When('I run :command in the :service service of the Composer project')]
    public function runCommandInComposerProjectService(string $command, string $service): void
    {
        $this->runShellCommandInProjectService($command, $service);
    }

    #[When('I run :command in the :service service of the Laravel project')]
    public function runCommandInLaravelProjectService(string $command, string $service): void
    {
        $this->runShellCommandInProjectService($command, $service);
    }

    #[When('I delete the project file :path')]
    public function deleteProjectFile(string $path): void
    {
        @unlink($this->getProjectDirectory() . '/' . $path);
    }

    #[Then('the project file :path should contain :expected')]
    public function assertProjectFileShouldContain(string $path, string $expected): void
    {
        $filePath = $this->getProjectDirectory() . '/' . $path;

        Assert::assertFileExists($filePath);
        Assert::assertSame($expected, trim((string) file_get_contents($filePath)));
    }

    #[Then('the last command error output should contain :expected')]
    public function assertLastCommandErrorOutputShouldContain(string $expected): void
    {
        Assert::assertStringContainsString($expected, $this->getLastCommandResult()['stderr']);
    }

    protected function getProjectDirectory(): string
    {
        if ($this->projectDirectory === null) {
            throw new RuntimeException('No temporary project has been created for this scenario.');
        }

        return $this->projectDirectory;
    }

    /**
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    protected function getLastCommandResult(): array
    {
        if ($this->lastCommandResult === null) {
            throw new RuntimeException('No command has been run yet.');
        }

        return $this->lastCommandResult;
    }

    protected function createTemporaryProjectDirectory(string $prefix): string
    {
        $projectDirectory = $this->getTempDir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        if (! mkdir($projectDirectory, 0o777, true) && ! is_dir($projectDirectory)) {
            throw new RuntimeException("Unable to create integration project directory `$projectDirectory`.");
        }

        $this->projectDirectories[] = $projectDirectory;

        return $projectDirectory;
    }

    protected function getComposerImage(): string
    {
        return 'composer:2';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function writeJson(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException(sprintf('Unable to encode "%s".', $path));
        }

        file_put_contents($path, $encoded . PHP_EOL);
    }

    /**
     * @param  list<string>          $command
     * @param  array<string, string> $environment
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    protected function runCommand(array $command, string $workingDirectory, array $environment = [], bool $failOnError = true): array
    {
        return $this->runProcess($command, $workingDirectory, $environment, $failOnError);
    }

    /**
     * @param  array<string, string>  $environment
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    protected function runShellCommand(string $command, string $workingDirectory, array $environment = [], bool $failOnError = true): array
    {
        return $this->runProcess($command, $workingDirectory, $environment, $failOnError);
    }

    /**
     * @param  list<string>|string    $command
     * @param  array<string, string>  $environment
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runProcess(array|string $command, string $workingDirectory, array $environment = [], bool $failOnError = true): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $processEnvironment = array_merge(getenv() ?: [], [
            'COMPOSER_CACHE_DIR' => dirname(__DIR__, 2) . '/var/cache/composer',
            'COMPOSER_NO_INTERACTION' => '1',
        ], $environment);

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $processEnvironment);
        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start command: %s', $this->formatCommand($command)));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($failOnError && $exitCode !== 0) {
            Assert::fail(sprintf(
                "Command failed with exit code %d:\n%s\n\nSTDOUT:\n%s\n\nSTDERR:\n%s",
                $exitCode,
                $this->formatCommand($command),
                $stdout,
                $stderr,
            ));
        }

        return [
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
            'exitCode' => $exitCode,
        ];
    }

    private function runShellCommandInProjectService(string $command, string $service): void
    {
        $environment = $this->explicitProjectCommandEnvironment();
        $dockerCommand = [
            'docker',
            'compose',
            'run',
            '--rm',
            '-T',
            '--workdir',
            '/usr/src/app',
            '--env',
            'DOCKER_COMPOSER_INSIDE=1',
        ];

        foreach ($environment as $name => $value) {
            $dockerCommand[] = '--env';
            $dockerCommand[] = $name . '=' . $value;
        }

        $this->lastCommandResult = $this->runCommand(
            array_merge($dockerCommand, [$service, 'sh', '-lc', $command]),
            $this->getProjectDirectory(),
            $environment,
        );
    }

    /**
     * @return array<string, string>
     */
    private function explicitProjectCommandEnvironment(): array
    {
        if ($this->dependencyResolutionMode === 'prefer-lowest') {
            return ['COMPOSER_PREFER_LOWEST' => '1'];
        }

        return [];
    }

    /**
     * @param  list<string>|string  $command
     */
    private function formatCommand(array|string $command): string
    {
        if (is_string($command)) {
            return $command;
        }

        return implode(' ', $command);
    }

    private function setDependencyResolutionMode(string $dependencyResolutionMode): void
    {
        if (! in_array($dependencyResolutionMode, ['latest', 'prefer-lowest'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported dependency resolution mode "%s".', $dependencyResolutionMode));
        }

        $this->dependencyResolutionMode = $dependencyResolutionMode;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
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

    private function getTempDir(): string
    {
        return dirname(__DIR__, 2) . '/var/tmp/features';
        //return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    }
}
