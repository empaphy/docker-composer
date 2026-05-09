<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Script\Event as ScriptEvent;
use empaphy\docker_composer\ComposerProcessRunner;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposerConfig;
use empaphy\docker_composer\DockerComposerPlugin;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Tests\Unit\Mocks\MockCommandBuilder;
use Tests\Unit\Mocks\MockContainerDetector;
use Tests\Unit\Mocks\MockOutputCapturingProcessRunner;
use Tests\Unit\Mocks\MockProcessRunner;
use Symfony\Component\Console\Input\ArgvInput;

#[CoversClass(DockerComposerPlugin::class)]
#[CoversClass(ComposerProcessRunner::class)]
#[CoversClass(DockerComposerConfig::class)]
#[CoversClass(DockerComposeCommandBuilder::class)]
class DockerComposerPluginTest extends TestCase
{
    public function testPluginLifecycleMethodsAreSafe(): void
    {
        [$composer, $io] = $this->createComposer([], []);
        $plugin = new DockerComposerPlugin(new MockProcessRunner(), new MockContainerDetector(false));

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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
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
                '--no-interaction',
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

    public function testServiceMappingOverrideChangesTargetService(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            [
                'docker-composer' => [
                    'service' => 'php',
                    'service-mapping' => [
                        'php-test' => 'test',
                    ],
                ],
            ],
        );
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame('php-test', $runner->commands[0][4]);
        self::assertSame('php-test', $runner->commands[1][6]);
        self::assertStringContainsString('Running test in Docker Compose service php-test.', $io->getOutput());
    }

    public function testRedirectNoticeEscapesScriptAndServiceNames(): void
    {
        [$composer, $io] = $this->createComposer(
            ['bad<error>script</error>' => ['host-command']],
            [
                'docker-composer' => [
                    'service' => 'php<error>service</error>',
                ],
            ],
        );
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('bad<error>script</error>', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertStringContainsString(
            'Running bad<error>script</error> in Docker Compose service php<error>service</error>.',
            $io->getOutput(),
        );
    }

    public function testServiceMappingOverrideCanConfigureServiceWithoutDefault(): void
    {
        [$composer, $io] = $this->createComposer(
            [
                'test' => ['host-command'],
                'cs' => ['host-command'],
            ],
            [
                'docker-composer' => [
                    'service-mapping' => [
                        'php-test' => 'test',
                    ],
                ],
            ],
        );
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $testEvent = new ScriptEvent('test', $composer, $io);
        $csEvent = new ScriptEvent('cs', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($testEvent);
        $plugin->onScript($csEvent);

        self::assertTrue($testEvent->isPropagationStopped());
        self::assertFalse($csEvent->isPropagationStopped());
        self::assertSame('php-test', $runner->commands[0][4]);
        self::assertSame('php-test', $runner->commands[1][6]);
        self::assertSame(1, substr_count($io->getOutput(), 'no default service and no service-mapping override for "cs"'));
    }

    public function testMissingServiceWarningEscapesScriptName(): void
    {
        [$composer, $io] = $this->createComposer(
            ['bad<error>script</error>' => ['host-command']],
            ['docker-composer' => []],
        );
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('bad<error>script</error>', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertSame([], $runner->commands);
        self::assertStringContainsString('no default service and no service-mapping override for "bad<error>script</error>"', $io->getOutput());
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

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
                '--no-interaction',
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
                '--no-interaction',
                '--no-dev',
                '--timeout=300',
                'cs',
            ],
        ], $runner->commands);
    }

    public function testExecModeStartsEachServiceMappingOverrideOnce(): void
    {
        [$composer, $io] = $this->createComposer(
            [
                'test' => ['host-command'],
                'stan' => ['host-command'],
                'test-again' => ['host-command'],
            ],
            [
                'docker-composer' => [
                    'service' => 'php',
                    'service-mapping' => [
                        'php-test' => ['test', 'test-again'],
                        'php-tools' => 'stan',
                    ],
                ],
            ],
        );
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->onScript(new ScriptEvent('test', $composer, $io));
        $plugin->onScript(new ScriptEvent('stan', $composer, $io));
        $plugin->onScript(new ScriptEvent('test-again', $composer, $io));

        self::assertSame([
            ['up', 'php-test'],
            ['exec', 'php-test'],
            ['up', 'php-tools'],
            ['exec', 'php-tools'],
            ['exec', 'php-test'],
        ], array_map(
            static fn(array $command): array => [$command[2], $command[$command[2] === 'up' ? 4 : 6]],
            $runner->commands,
        ));
    }

    public function testExecModeSkipsAutoUpWhenServiceIsAlreadyRunning(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            [
                'docker-composer' => [
                    'service' => 'php',
                    'compose-files' => ['docker-compose.yaml'],
                    'project-directory' => '.',
                ],
            ],
        );
        $runner = new MockOutputCapturingProcessRunner([0, 0], outputs: ['php' . PHP_EOL]);
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->onScript(new ScriptEvent('test', $composer, $io));

        self::assertSame([
            [
                'docker',
                'compose',
                '--file',
                'docker-compose.yaml',
                '--project-directory',
                '.',
                'ps',
                '--status',
                'running',
                '--services',
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
                '--no-interaction',
                '--no-dev',
                '--timeout=300',
                'test',
            ],
        ], $runner->commands);
    }

    public function testExecModeRunsAutoUpWhenServiceIsNotRunning(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            ['docker-composer' => ['service' => 'php']],
        );
        $runner = new MockOutputCapturingProcessRunner([0, 0, 0], outputs: ['']);
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->onScript(new ScriptEvent('test', $composer, $io));

        self::assertSame(['ps', 'up', 'exec'], [
            $runner->commands[0][2],
            $runner->commands[1][2],
            $runner->commands[2][2],
        ]);
    }

    public function testExecModeRunsAutoUpWhenRunningServiceCheckFails(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            ['docker-composer' => ['service' => 'php']],
        );
        $runner = new MockOutputCapturingProcessRunner([7, 0, 0], outputs: ['']);
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->onScript(new ScriptEvent('test', $composer, $io));

        self::assertSame(['ps', 'up', 'exec'], [
            $runner->commands[0][2],
            $runner->commands[1][2],
            $runner->commands[2][2],
        ]);
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
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
                '--no-interaction',
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(true));
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
            new MockContainerDetector(false),
            new MockCommandBuilder(),
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

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
        $runner = new MockProcessRunner(supportsTty: true);
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('prompt', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame([false, true], $runner->tty);
        self::assertNotContains('-T', $runner->commands[1]);
        self::assertNotContains('--no-interaction', $runner->commands[1]);
    }

    public function testInteractiveScriptsUseNonTtyExecutionWhenRunnerDoesNotSupportTty(): void
    {
        [$composer, $io] = $this->createComposer(
            ['prompt' => ['host-command']],
            ['docker-composer' => ['service' => 'php']],
        );
        $io->setUserInputs(['yes']);
        $runner = new MockProcessRunner(supportsTty: false);
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $firstEvent = new ScriptEvent('test', $composer, $io);
        $secondEvent = new ScriptEvent('cs', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->onScript($firstEvent);
        $plugin->onScript($secondEvent);

        self::assertFalse($firstEvent->isPropagationStopped());
        self::assertFalse($secondEvent->isPropagationStopped());
        self::assertSame([], $runner->commands);
        self::assertSame(1, substr_count($io->getOutput(), 'no default service and no service-mapping override for "test"'));
    }

    public function testRedirectsInstallCommandBeforeHostExecution(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'compose-files' => ['docker-compose.yaml'],
                'project-directory' => '.',
                'workdir' => '/usr/src/app',
            ],
        ]);
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $input = new ArgvInput(['composer', '--no-interaction', 'install', '--no-dev', '--working-dir', 'app', '--prefer-dist']);
        $input->setInteractive(false);
        $event = new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, 'install');

        $plugin->activate($composer, $io);
        $exception = $this->assertCommandExecutionStops($plugin, $event);

        self::assertSame(0, $exception->getCode());
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
                '--no-interaction',
                'install',
                '--no-dev',
                '--prefer-dist',
            ],
        ], $runner->commands);
        self::assertStringContainsString('Running composer install in Docker Compose service php.', $io->getOutput());
    }

    public function testRedirectsDependencyCommandsBeforeHostExecution(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'mode' => 'run',
            ],
        ]);
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

        $plugin->activate($composer, $io);

        foreach (['update', 'require', 'remove', 'reinstall'] as $commandName) {
            $input = new ArgvInput(['composer', $commandName, 'vendor/package']);
            $input->setInteractive(false);
            $this->assertCommandExecutionStops($plugin, new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, $commandName));
        }

        self::assertSame([
            ['run', 'composer', 'update', 'vendor/package'],
            ['run', 'composer', 'require', 'vendor/package'],
            ['run', 'composer', 'remove', 'vendor/package'],
            ['run', 'composer', 'reinstall', 'vendor/package'],
        ], array_map(
            static fn(array $command): array => [$command[2], $command[8], $command[9], $command[10]],
            $runner->commands,
        ));
    }

    public function testExcludedCommandFallsThrough(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'exclude' => ['install'],
            ],
        ]);
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $input = new ArgvInput(['composer', 'install']);
        $event = new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, 'install');

        $plugin->activate($composer, $io);
        $plugin->onCommand($event);

        self::assertSame([], $runner->commands);
    }

    public function testCommandDockerFailurePreservesExitCode(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new MockProcessRunner([0, 7], 'install failed');
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $input = new ArgvInput(['composer', 'install']);
        $event = new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, 'install');

        $plugin->activate($composer, $io);

        $exception = $this->assertCommandExecutionStops($plugin, $event);

        self::assertSame(7, $exception->getCode());
        self::assertStringContainsString('Docker Compose exec command failed with exit code 7.', $exception->getMessage());
        self::assertStringContainsString("'composer' 'install'", $exception->getMessage());
        self::assertStringContainsString('Error Output: install failed', $exception->getMessage());
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));

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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
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
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
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
        $runner = new MockProcessRunner([0, 7], 'docker failed');
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $exception = $this->assertScriptExecutionFails($plugin, $event);

        self::assertSame(7, $exception->getCode());
        self::assertStringContainsString('Docker Compose exec command failed with exit code 7.', $exception->getMessage());
        self::assertStringContainsString('Command:', $exception->getMessage());
        self::assertStringContainsString('run-script', $exception->getMessage());
        self::assertStringContainsString('Error Output: docker failed', $exception->getMessage());
    }

    public function testDockerUpFailurePreservesExitCode(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new MockProcessRunner([7], 'up failed');
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $exception = $this->assertScriptExecutionFails($plugin, $event);

        self::assertSame(7, $exception->getCode());
        self::assertStringContainsString('Docker Compose up command failed with exit code 7.', $exception->getMessage());
        self::assertStringContainsString('Command:', $exception->getMessage());
        self::assertStringContainsString('up', $exception->getMessage());
        self::assertStringContainsString('Error Output: up failed', $exception->getMessage());
    }

    public function testDockerRunFailureReportsRunPhase(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'mode' => 'run',
            ],
        ]);
        $runner = new MockProcessRunner([7], 'run failed');
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $exception = $this->assertScriptExecutionFails($plugin, $event);

        self::assertSame(7, $exception->getCode());
        self::assertStringContainsString('Docker Compose run command failed with exit code 7.', $exception->getMessage());
        self::assertStringContainsString('Command:', $exception->getMessage());
        self::assertStringContainsString('run', $exception->getMessage());
        self::assertStringContainsString('Error Output: run failed', $exception->getMessage());
    }

    public function testDockerFailureUsesGenericMessageWithoutErrorOutput(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new MockProcessRunner([0, 7]);
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);

        $exception = $this->assertScriptExecutionFails($plugin, $event);

        self::assertSame(7, $exception->getCode());
        self::assertStringContainsString('Docker Compose exec command failed with exit code 7.', $exception->getMessage());
        self::assertStringContainsString('Command:', $exception->getMessage());
        self::assertStringNotContainsString('Error Output:', $exception->getMessage());
    }

    public function testInvalidKnownConfigFailsStrictly(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['mode' => 'invalid'],
        ]);
        $plugin = new DockerComposerPlugin(new MockProcessRunner(), new MockContainerDetector(false));

        $this->expectException(InvalidArgumentException::class);
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
        $plugin = new DockerComposerPlugin(new MockProcessRunner(), new MockContainerDetector(false));

        $plugin->activate($composer, $io);
        $plugin->activate($composer, $io);

        self::assertStringContainsString('Unknown extra.docker-composer key "future-key" will be ignored.', $io->getOutput());
        self::assertSame(1, substr_count($io->getOutput(), 'future-key'));
    }

    public function testDuplicateServiceMappingScriptForSameServiceWarnsAndContinues(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            [
                'docker-composer' => [
                    'service-mapping' => [
                        'php' => ['test', 'test'],
                    ],
                ],
            ],
        );
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->activate($composer, $io);
        $plugin->activate($composer, $io);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame('php', $runner->commands[0][4]);
        self::assertSame('php', $runner->commands[1][6]);
        self::assertSame(1, substr_count($io->getOutput(), 'duplicate service-mapping script "test" for service "php" will be ignored.'));
    }

    public function testDuplicateServiceMappingScriptWarnsWithoutActivation(): void
    {
        [$composer, $io] = $this->createComposer(
            ['test' => ['host-command']],
            [
                'docker-composer' => [
                    'service-mapping' => [
                        'php' => ['test', 'test'],
                    ],
                ],
            ],
        );
        $plugin = new DockerComposerPlugin(
            new MockProcessRunner(),
            new MockContainerDetector(false),
        );
        $event = new ScriptEvent('test', $composer, $io);

        $plugin->onScript($event);
        $plugin->onScript($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(1, substr_count($io->getOutput(), 'duplicate service-mapping script "test" for service "php" will be ignored.'));
    }

    #[BackupGlobals(true)]
    public function testDisableEnvironmentVariableFallsThrough(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $runner = new MockProcessRunner();
        $plugin = new DockerComposerPlugin($runner, new MockContainerDetector(false));
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

    private function assertScriptExecutionFails(DockerComposerPlugin $plugin, ScriptEvent $event): ScriptExecutionException
    {
        try {
            $plugin->onScript($event);
        } catch (ScriptExecutionException $exception) {
            return $exception;
        }

        self::fail('Expected Docker script execution to fail.');
    }

    private function assertCommandExecutionStops(DockerComposerPlugin $plugin, PreCommandRunEvent $event): ScriptExecutionException
    {
        try {
            $plugin->onCommand($event);
        } catch (ScriptExecutionException $exception) {
            return $exception;
        }

        self::fail('Expected Docker command execution to stop host command.');
    }
}
