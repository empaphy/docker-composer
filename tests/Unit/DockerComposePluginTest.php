<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use empaphy\docker_composer\ComposerProcessRunner;
use empaphy\docker_composer\ContainerDetector;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposerConfig;
use empaphy\docker_composer\DockerComposerPlugin;
use empaphy\docker_composer\EnvironmentContainerDetector;
use empaphy\docker_composer\ProcessRunner;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\TestCase;

#[CoversClass(DockerComposerPlugin::class)]
#[CoversClass(DockerComposerConfig::class)]
#[CoversClass(DockerComposeCommandBuilder::class)]
#[CoversClass(ComposerProcessRunner::class)]
#[CoversClass(EnvironmentContainerDetector::class)]
class DockerComposePluginTest extends TestCase
{
    public function testPluginLifecycleMethodsAreSafe(): void
    {
        [$composer, $io] = $this->createComposer([], []);
        $plugin = new DockerComposerPlugin(new TestProcessRunner(), new TestContainerDetector(false));

        self::assertSame([], DockerComposerPlugin::getSubscribedEvents());

        $plugin->activate($composer, $io);
        $plugin->deactivate($composer, $io);
        $plugin->uninstall($composer, $io);

        self::assertSame('', $io->getOutput());
    }

    public function testRedirectsCustomScriptWithExecModeAndAutoUp(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            [
                'docker-composer' => [
                    'service' => 'php',
                    'compose-files' => ['docker-compose.yaml'],
                    'project-directory' => '.',
                    'workdir' => '/usr/src/app',
                ],
            ],
        );
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io, false, ['--filter', 'Example']);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame([
            [
                'docker',
                'compose',
                '--file',
                'docker-compose.yaml',
                '--project-directory',
                '.',
                'up',
                '-d',
                'php',
            ],
            [
                'docker',
                'compose',
                '--file',
                'docker-compose.yaml',
                '--project-directory',
                '.',
                'exec',
                '-T',
                '--workdir',
                '/usr/src/app',
                '--env',
                'DOCKER_COMPOSER_INSIDE=1',
                'php',
                'composer',
                'run-script',
                '--no-dev',
                '--timeout=300',
                'test',
                '--',
                '--filter',
                'Example',
            ],
        ], $runner->commands);
        self::assertStringContainsString('Running test in Docker Compose service php.', $io->getOutput());
    }

    public function testExecModeStartsServiceOnlyOncePerComposeTarget(): void
    {
        [$composer, $io] = $this->createComposer(
            [
                'test' => ['host-command'],
                'cs' => ['host-command'],
            ],
            [
                'docker-composer' => [
                    'service' => 'php',
                    'compose-files' => ['docker-compose.yaml'],
                    'project-directory' => '.',
                ],
            ],
        );
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->onScript(new ScriptEvent('test', $composer, $io));
        $plugin->onScript(new ScriptEvent('cs', $composer, $io));

        self::assertSame([
            [
                'docker',
                'compose',
                '--file',
                'docker-compose.yaml',
                '--project-directory',
                '.',
                'up',
                '-d',
                'php',
            ],
            [
                'docker',
                'compose',
                '--file',
                'docker-compose.yaml',
                '--project-directory',
                '.',
                'exec',
                '-T',
                '--env',
                'DOCKER_COMPOSER_INSIDE=1',
                'php',
                'composer',
                'run-script',
                '--no-dev',
                '--timeout=300',
                'test',
            ],
            [
                'docker',
                'compose',
                '--file',
                'docker-compose.yaml',
                '--project-directory',
                '.',
                'exec',
                '-T',
                '--env',
                'DOCKER_COMPOSER_INSIDE=1',
                'php',
                'composer',
                'run-script',
                '--no-dev',
                '--timeout=300',
                'cs',
            ],
        ], $runner->commands);
    }

    public function testRunModeUsesOneOffContainerWithoutAutoUp(): void
    {
        [$composer, $io] = $this->createComposer(
            ['cs' => ['host-command']],
            [
                'docker-composer' => [
                    'service' => 'php',
                    'mode' => 'run',
                    'compose-files' => 'compose.yaml',
                ],
            ],
        );
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('cs', $composer, $io, true);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame([
            [
                'docker',
                'compose',
                '--file',
                'compose.yaml',
                'run',
                '--rm',
                '-T',
                '--env',
                'DOCKER_COMPOSER_INSIDE=1',
                'php',
                'composer',
                'run-script',
                '--dev',
                '--timeout=300',
                'cs',
            ],
        ], $runner->commands);
    }

    public function testContainerExecutionFallsThroughToComposerScripts(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(true));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertSame([], $runner->commands);
    }

    public function testCanRunWithoutActivationWhenCalledDirectly(): void
    {
        [$composer, $io] = $this->createComposer(
            [],
            ['docker-composer' => ['service' => 'php']],
        );
        $plugin = new DockerComposerPlugin(
            null,
            new TestContainerDetector(false),
            new TestCommandBuilder(),
        );
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
    }

    public function testUnconfiguredLifecycleEventIsNotRedirected(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));

        $plugin->activate($composer, $io);

        self::assertSame(0, $composer->getEventDispatcher()->dispatchScript('pre-autoload-dump'));
        self::assertSame([], $runner->commands);
    }

    public function testInteractiveScriptsUseTtyExecution(): void
    {
        [$composer, $io] = $this->createComposer(
            ['prompt' => ['host-command']],
            ['docker-composer' => ['service' => 'php']],
        );
        $io->setUserInputs(['yes']);
        $runner = new TestProcessRunner(supportsTty: true);
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('prompt', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame([false, true], $runner->tty);
        self::assertNotContains('-T', $runner->commands[1]);
    }

    public function testInteractiveScriptsUseNonTtyExecutionWhenRunnerDoesNotSupportTty(): void
    {
        [$composer, $io] = $this->createComposer(
            ['prompt' => ['host-command']],
            ['docker-composer' => ['service' => 'php']],
        );
        $io->setUserInputs(['yes']);
        $runner = new TestProcessRunner(supportsTty: false);
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('prompt', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame([false, false], $runner->tty);
        self::assertContains('-T', $runner->commands[1]);
    }

    public function testMissingServiceWarnsOnceAndFallsThrough(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [],
        ]);
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $firstEvent = new ScriptEvent('test', $composer, $io);
        $secondEvent = new ScriptEvent('cs', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($firstEvent);
        $plugin->onScript($secondEvent);

        self::assertFalse($firstEvent->isPropagationStopped());
        self::assertFalse($secondEvent->isPropagationStopped());
        self::assertSame([], $runner->commands);
        self::assertSame(1, substr_count($io->getOutput(), 'extra.docker-composer.service is not configured'));
    }

    public function testEmptyAndInvalidScriptNamesAreIgnoredDuringActivation(): void
    {
        [$composer, $io] = $this->createComposer(
            [
                'empty-script' => [],
                '' => ['host-command'],
            ],
            ['docker-composer' => ['service' => 'php']],
        );
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));

        $plugin->activate($composer, $io);

        self::assertSame(0, $composer->getEventDispatcher()->dispatchScript('empty-script'));
        self::assertSame([], $runner->commands);
    }

    public function testExcludedScriptFallsThrough(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'exclude' => ['test'],
            ],
        ]);
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertSame([], $runner->commands);
    }

    public function testNestedScriptFallsThrough(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('child', $composer, $io);
        $event->setOriginatingEvent(new Event('parent'));

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertSame([], $runner->commands);
    }

    public function testDockerFailurePreservesExitCode(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner([0, 7], 'docker failed');
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $this->expectException(ScriptExecutionException::class);
        $this->expectExceptionCode(7);
        $this->expectExceptionMessage('docker failed');

        $plugin->onScript($event);
    }

    public function testDockerUpFailurePreservesExitCode(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner([7], 'up failed');
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $this->expectException(ScriptExecutionException::class);
        $this->expectExceptionCode(7);
        $this->expectExceptionMessage('up failed');

        $plugin->onScript($event);
    }

    public function testDockerFailureUsesGenericMessageWithoutErrorOutput(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner([0, 7]);
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $this->expectException(ScriptExecutionException::class);
        $this->expectExceptionCode(7);
        $this->expectExceptionMessage('Docker Compose command failed.');

        $plugin->onScript($event);
    }

    public function testInvalidKnownConfigFailsStrictly(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['mode' => 'invalid'],
        ]);
        $plugin = new DockerComposerPlugin(new TestProcessRunner(), new TestContainerDetector(false));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('extra.docker-composer.mode must be "exec" or "run".');

        $plugin->activate($composer, $io);
    }

    public function testConfigDefaultsWhenDockerComposerExtraIsMissing(): void
    {
        [$composer] = $this->createComposer([], []);

        $config = DockerComposerConfig::fromComposer($composer);

        self::assertFalse($config->isConfigured());
        self::assertSame(DockerComposerConfig::MODE_EXEC, $config->getMode());
        self::assertSame([], $config->getComposeFiles());
        self::assertNull($config->getProjectDirectory());
        self::assertNull($config->getWorkdir());
        self::assertFalse($config->isExcluded('test'));
        self::assertSame([], $config->getUnknownKeys());
    }

    public function testConfigRejectsInvalidShapes(): void
    {
        $this->assertInvalidConfig(['docker-composer' => 'invalid'], 'extra.docker-composer must be an object.');
        $this->assertInvalidConfig(['docker-composer' => [0 => 'invalid']], 'extra.docker-composer must be an object.');
        $this->assertInvalidConfig(['docker-composer' => ['service' => '']], 'extra.docker-composer.service must be a non-empty string.');
        $this->assertInvalidConfig(['docker-composer' => ['compose-files' => '']], 'extra.docker-composer.compose-files must contain non-empty strings.');
        $this->assertInvalidConfig(['docker-composer' => ['exclude' => ['script' => true]]], 'extra.docker-composer.exclude must be a list of strings.');
        $this->assertInvalidConfig(['docker-composer' => ['exclude' => [1]]], 'extra.docker-composer.exclude must contain only non-empty strings.');

    }

    public function testUnconfiguredServiceAccessFails(): void
    {
        [$composer] = $this->createComposer([], []);
        $config = DockerComposerConfig::fromComposer($composer);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Docker Compose service is not configured.');

        $config->getService();
    }

    public function testUnknownConfigKeysWarnAndContinue(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'future-key' => true,
            ],
        ]);
        $plugin = new DockerComposerPlugin(new TestProcessRunner(), new TestContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->activate($composer, $io);

        self::assertStringContainsString('Unknown extra.docker-composer key "future-key" will be ignored.', $io->getOutput());
        self::assertSame(1, substr_count($io->getOutput(), 'future-key'));
    }

    #[BackupGlobals(true)]
    public function testDisableEnvironmentVariableFallsThrough(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new TestProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new TestContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        putenv('DOCKER_COMPOSER_DISABLE=1');
        try {
            $plugin->activate($composer, $io);
            $plugin->onScript($event);
        } finally {
            putenv('DOCKER_COMPOSER_DISABLE');
        }

        self::assertFalse($event->isPropagationStopped());
        self::assertSame([], $runner->commands);
    }

    public function testCommandBuilderStringifiesNullAndBoolArguments(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $event = new ScriptEvent('test', $composer, $io, false, [null, true]);

        $command = (new DockerComposeCommandBuilder())->buildScriptCommand($config, $event, false);

        self::assertSame(['--', '', '1'], array_slice($command, -3));
    }

    public function testCommandBuilderForwardsComposerProcessTimeout(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $previousTimeout = ProcessExecutor::getTimeout();

        ProcessExecutor::setTimeout(42);
        try {
            $config = DockerComposerConfig::fromComposer($composer);
            $event = new ScriptEvent('test', $composer, $io);

            $command = (new DockerComposeCommandBuilder())->buildScriptCommand($config, $event, false);
        } finally {
            ProcessExecutor::setTimeout($previousTimeout);
        }

        self::assertSame('--timeout=42', $command[count($command) - 2]);
    }

    public function testCommandBuilderRejectsNonScalarArguments(): void
    {
        $method = new \ReflectionMethod(DockerComposeCommandBuilder::class, 'stringifyArgument');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer script arguments must be scalar values.');

        $method->invoke(new DockerComposeCommandBuilder(), []);
    }

    #[BackupGlobals(true)]
    public function testEnvironmentDetectorUsesExplicitMarker(): void
    {
        putenv('DOCKER_COMPOSER_INSIDE=1');
        try {
            self::assertTrue((new EnvironmentContainerDetector())->isInsideContainer());
        } finally {
            putenv('DOCKER_COMPOSER_INSIDE');
        }
    }

    public function testEnvironmentDetectorUsesContainerFilesAndCgroups(): void
    {
        $getEnv = static fn(string $name): bool => false;
        $missingFiles = static fn(string $path): bool => false;

        self::assertTrue((new EnvironmentContainerDetector(
            $getEnv,
            static fn(string $path): bool => $path === '/.dockerenv',
            static fn(string $path): bool => false,
        ))->isInsideContainer());
        self::assertFalse((new EnvironmentContainerDetector(
            $getEnv,
            $missingFiles,
            static fn(string $path): bool => false,
        ))->isInsideContainer());
        self::assertFalse((new EnvironmentContainerDetector(
            $getEnv,
            $missingFiles,
            static fn(string $path): string => '0::/user.slice',
        ))->isInsideContainer());
        self::assertTrue((new EnvironmentContainerDetector(
            $getEnv,
            $missingFiles,
            static fn(string $path): string => '0::/kubepods.slice/containerd',
        ))->isInsideContainer());
    }

    public function testComposerProcessRunnerDelegatesToProcessExecutor(): void
    {
        $io = new BufferIO();
        $runner = new ComposerProcessRunner($io);
        $processExecutor = new TestProcessExecutor(3, 4, 'executor error');
        $property = new \ReflectionProperty($runner, 'processExecutor');
        $property->setValue($runner, $processExecutor);

        self::assertTrue($runner->supportsTty());
        self::assertSame(3, $runner->run(['docker', 'compose']));
        self::assertSame(4, $runner->run(['docker', 'compose'], true));
        self::assertSame('executor error', $runner->getErrorOutput());
        self::assertSame(["'docker' 'compose'"], $processExecutor->commands);
        self::assertSame(["'docker' 'compose'"], $processExecutor->ttyCommands);
    }

    /**
     * @param array<string, list<string>> $scripts
     * @param array<string, mixed>        $extra
     *
     * @return array{0: Composer, 1: BufferIO}
     */
    private function createComposer(array $scripts, array $extra): array
    {
        $composer = new Composer();
        $package = new RootPackage('root/project', '1.0.0', '1.0.0');
        $package->setScripts($scripts);
        $package->setExtra($extra);
        $composer->setPackage($package);
        $composer->setConfig(new Config(false, getcwd() ?: null));

        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL);
        $dispatcher = new EventDispatcher($composer, $io);
        $composer->setEventDispatcher($dispatcher);

        return [$composer, $io];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function assertInvalidConfig(array $extra, string $message): void
    {
        [$composer] = $this->createComposer([], $extra);

        try {
            DockerComposerConfig::fromComposer($composer);
            self::fail(sprintf('Expected invalid config exception for message "%s".', $message));
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final class TestContainerDetector implements ContainerDetector
{
    public function __construct(private bool $inside) {}

    public function isInsideContainer(): bool
    {
        return $this->inside;
    }
}

final class TestProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<bool> */
    public array $tty = [];

    /** @var list<int> */
    private array $exitCodes;

    /**
     * @param list<int> $exitCodes
     */
    public function __construct(
        array $exitCodes = [0],
        private string $errorOutput = '',
        private bool $supportsTty = false,
    ) {
        $this->exitCodes = $exitCodes;
    }

    public function run(array $command, bool $tty = false): int
    {
        $this->commands[] = $command;
        $this->tty[] = $tty;

        return array_shift($this->exitCodes) ?? 0;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    public function supportsTty(): bool
    {
        return $this->supportsTty;
    }
}

final class TestCommandBuilder extends DockerComposeCommandBuilder
{
    public function buildUpCommand(DockerComposerConfig $config): array
    {
        return ['php', '-r', 'exit(0);'];
    }

    public function buildScriptCommand(DockerComposerConfig $config, ScriptEvent $event, bool $interactive): array
    {
        return ['php', '-r', 'exit(0);'];
    }
}

final class TestProcessExecutor extends ProcessExecutor
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    public array $ttyCommands = [];

    public function __construct(
        private int $executeExitCode,
        private int $ttyExitCode,
        private string $testErrorOutput,
    ) {}

    /**
     * @param mixed $command
     * @param mixed $output
     */
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        $this->commands[] = (string) $command;

        return $this->executeExitCode;
    }

    /**
     * @param mixed $command
     */
    public function executeTty($command, ?string $cwd = null): int
    {
        $this->ttyCommands[] = (string) $command;

        return $this->ttyExitCode;
    }

    public function getErrorOutput(): string
    {
        return $this->testErrorOutput;
    }
}
