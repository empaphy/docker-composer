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
                'test',
                '--',
                '--filter',
                'Example',
            ],
        ], $runner->commands);
        self::assertStringContainsString('Running test in Docker Compose service php.', $io->getOutput());
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

        self::assertStringContainsString('Unknown extra.docker-composer key "future-key" will be ignored.', $io->getOutput());
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
