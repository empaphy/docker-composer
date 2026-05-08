<?php

/**
 * Defines the Composer plugin entrypoint.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 *
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;

/**
 * Redirects Composer scripts into a configured Docker Compose service.
 */
class DockerComposerPlugin implements EventSubscriberInterface, PluginInterface
{
    /**
     * Stores Composer IO for plugin messages.
     */
    private ?IOInterface $io = null;

    /**
     * Stores parsed Docker Composer configuration.
     */
    private ?DockerComposerConfig $config = null;

    /**
     * Stores the process runner used for Docker commands.
     */
    private ?ProcessRunner $processRunner;

    /**
     * Detects whether the current process already runs in a container.
     */
    private ContainerDetector $containerDetector;

    /**
     * Builds Docker Compose command arguments.
     */
    private DockerComposeCommandBuilder $commandBuilder;

    /**
     * Tracks whether the missing configuration warning was written.
     */
    private bool $missingConfigWarningWritten = false;

    /**
     * Tracks whether unknown configuration warnings were written.
     */
    private bool $unknownConfigWarningWritten = false;

    /**
     * Tracks services started for Docker Compose exec mode.
     *
     * Stores startup keys for services already started during this process.
     *
     * @var array<string, true>
     */
    private array $startedExecServices = [];

    /**
     * Creates a Composer plugin with optional collaborators.
     *
     * @param  ProcessRunner|null  $processRunner
     *   The runner for Docker Compose commands, or `null` for the default.
     *
     * @param  ContainerDetector|null  $containerDetector
     *   The container detector, or `null` for environment-based detection.
     *
     * @param  DockerComposeCommandBuilder|null  $commandBuilder
     *   The command builder, or `null` for the default builder.
     */
    public function __construct(
        ?ProcessRunner $processRunner = null,
        ?ContainerDetector $containerDetector = null,
        ?DockerComposeCommandBuilder $commandBuilder = null,
    ) {
        $this->processRunner = $processRunner;
        $this->containerDetector = $containerDetector ?? new EnvironmentContainerDetector();
        $this->commandBuilder = $commandBuilder ?? new DockerComposeCommandBuilder();
    }

    /**
     * Applies plugin modifications to Composer.
     *
     * @param  Composer  $composer
     *   The Composer instance being activated.
     *
     * @param  IOInterface  $io
     *   The Composer IO used for plugin output.
     *
     * @return void
     *   Returns nothing.
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->config = DockerComposerConfig::fromComposer($composer);
        $this->processRunner ??= new ComposerProcessRunner($io);

        $this->writeUnknownConfigWarning();
        $this->registerScriptListeners($composer);
    }

    /**
     * Removes any hooks from Composer.
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     *
     * @param  Composer  $composer
     *   The Composer instance being deactivated.
     *
     * @param  IOInterface  $io
     *   The Composer IO available during deactivation.
     *
     * @return void
     *   Returns nothing.
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $dispatcher = $composer->getEventDispatcher();
        if ((new \ReflectionObject($dispatcher))->hasMethod('removeListener')) {
            $dispatcher->removeListener($this);
        }
    }

    /**
     * Prepares the plugin to be uninstalled.
     *
     * This will be called after deactivate.
     *
     * @param  Composer  $composer
     *   The Composer instance being uninstalled from.
     *
     * @param  IOInterface  $io
     *   The Composer IO available during uninstall.
     *
     * @return void
     *   Returns nothing.
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // Nothing to clean up.
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *   - The method name to call (priority defaults to 0)
     *   - An array composed of the method name to call and the priority
     *   - An array of arrays composed of the method names to call and
     *     respective priorities, or 0 if unset
     *
     * For instance:
     *
     *   - array('eventName' => 'methodName')
     *   - array('eventName' => array('methodName', $priority))
     *   - array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array<string, string|array{0: string, 1?: int}|array<array{0: string, 1?: int}>>
     *   Returns no static subscriptions because script listeners are registered after activation.
     */
    public static function getSubscribedEvents()
    {
        return [];
    }

    /**
     * Redirects a Composer script event into Docker Compose.
     *
     * @param  ScriptEvent  $event
     *   The Composer script event to inspect and possibly redirect.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Thrown when a Docker Compose command exits with failure.
     */
    public function onScript(ScriptEvent $event): void
    {
        if ($this->isNestedScript($event) || $this->isDisabledByEnvironment() || $this->containerDetector->isInsideContainer()) {
            return;
        }

        $config = $this->getConfig($event);
        if ($config->isExcluded($event->getName())) {
            return;
        }

        if (! $config->isConfiguredForScript($event->getName())) {
            $this->writeMissingConfigWarning($event->getIO());

            return;
        }

        $scriptConfig = $config->forScript($event->getName());

        $this->writeRedirectNotice($event, $scriptConfig);
        $this->runInDocker($event, $scriptConfig);
        $event->stopPropagation();
    }

    /**
     * Registers listeners for configured Composer scripts.
     *
     * @param  Composer  $composer
     *   The Composer instance whose package scripts should be watched.
     *
     * @return void
     *   Returns nothing.
     */
    private function registerScriptListeners(Composer $composer): void
    {
        $scriptNames = [];
        foreach ($composer->getPackage()->getScripts() as $scriptName => $scriptHandlers) {
            if (! is_array($scriptHandlers) || $scriptHandlers === []) {
                continue;
            }

            $scriptNames[] = $scriptName;
        }

        $scriptNames = array_unique($scriptNames);

        foreach ($scriptNames as $scriptName) {
            if (! is_string($scriptName) || $scriptName === '') {
                continue;
            }

            $composer->getEventDispatcher()->addListener($scriptName, [$this, 'onScript'], PHP_INT_MAX);
        }
    }

    /**
     * Gets cached plugin configuration.
     *
     * @param  ScriptEvent  $event
     *   The script event used to lazily access Composer.
     *
     * @return DockerComposerConfig
     *   Returns parsed Docker Composer configuration.
     */
    private function getConfig(ScriptEvent $event): DockerComposerConfig
    {
        if ($this->config === null) {
            $this->config = DockerComposerConfig::fromComposer($event->getComposer());
        }

        return $this->config;
    }

    /**
     * Writes warnings for ignored configuration keys.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeUnknownConfigWarning(): void
    {
        if ($this->unknownConfigWarningWritten || $this->config === null || $this->io === null) {
            return;
        }

        foreach ($this->config->getUnknownKeys() as $key) {
            $this->io->writeError(sprintf(
                '<warning>docker-composer: Unknown extra.docker-composer key "%s" will be ignored.</warning>',
                $key,
            ));
        }

        $this->unknownConfigWarningWritten = true;
    }

    /**
     * Writes the missing service configuration warning.
     *
     * @param  IOInterface  $io
     *   The Composer IO that receives the warning.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeMissingConfigWarning(IOInterface $io): void
    {
        if ($this->missingConfigWarningWritten) {
            return;
        }

        $io->writeError(
            '<warning>docker-composer: no extra.docker-composer.service or script-services entry is configured; running Composer scripts on the host.</warning>',
        );
        $this->missingConfigWarningWritten = true;
    }

    /**
     * Writes the script redirection notice.
     *
     * @param  ScriptEvent  $event
     *   The script event being redirected.
     *
     * @param  DockerComposerConfig  $config
     *   The configuration that provides the target service.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeRedirectNotice(ScriptEvent $event, DockerComposerConfig $config): void
    {
        $event->getIO()->writeError(sprintf(
            '<info>docker-composer:</info> Running <comment>%s</comment> in Docker Compose service <comment>%s</comment>.',
            $event->getName(),
            $config->getService(),
        ));
    }

    /**
     * Runs a Composer script inside Docker Compose.
     *
     * @param  ScriptEvent  $event
     *   The Composer script event being executed.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration used to build commands.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Thrown when Docker Compose startup or script execution fails.
     */
    private function runInDocker(ScriptEvent $event, DockerComposerConfig $config): void
    {
        $runner = $this->getProcessRunner($event);

        if ($config->getMode() === DockerComposerConfig::MODE_EXEC) {
            $startupKey = $this->getExecServiceStartupKey($config);
            if (! isset($this->startedExecServices[$startupKey])) {
                $this->ensureExecServiceStarted($runner, $config);
                $this->startedExecServices[$startupKey] = true;
            }
        }

        $isInteractive = $event->getIO()->isInteractive() && $runner->supportsTty();
        $scriptCommand = $this->commandBuilder->buildScriptCommand(
            $config,
            $event,
            $isInteractive,
        );
        $exitCode = $runner->run($scriptCommand, $isInteractive);
        if ($exitCode !== 0) {
            $this->throwScriptExecutionException($runner, $exitCode, $config->getMode(), $scriptCommand);
        }
    }

    /**
     * Ensures the configured service can receive `docker compose exec`.
     *
     * @param  ProcessRunner  $runner
     *   The runner used for Docker Compose commands.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that identifies the service.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Thrown when Docker Compose startup fails.
     */
    private function ensureExecServiceStarted(ProcessRunner $runner, DockerComposerConfig $config): void
    {
        if ($this->isExecServiceRunning($runner, $config)) {
            return;
        }

        $upCommand = $this->commandBuilder->buildUpCommand($config);
        $exitCode = $runner->run($upCommand);
        if ($exitCode !== 0) {
            $this->throwScriptExecutionException($runner, $exitCode, 'up', $upCommand);
        }
    }

    /**
     * Checks whether the configured exec-mode service is running.
     *
     * @param  ProcessRunner  $runner
     *   The runner used for Docker Compose commands.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that identifies the service.
     *
     * @return bool
     *   Returns `true` when Docker Compose lists the service as running.
     */
    private function isExecServiceRunning(ProcessRunner $runner, DockerComposerConfig $config): bool
    {
        if (! $runner instanceof OutputCapturingProcessRunner) {
            return false;
        }

        $command = $this->commandBuilder->buildRunningServicesCommand($config);
        $output = '';
        if ($runner->runWithOutput($command, $output) !== 0) {
            return false;
        }

        $services = preg_split('/\R/', trim($output));
        if ($services === false) {
            return false;
        }

        foreach ($services as $service) {
            if (trim($service) === $config->getService()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the process runner for Docker commands.
     *
     * @param  ScriptEvent  $event
     *   The script event used to create a default Composer runner.
     *
     * @return ProcessRunner
     *   Returns the configured or lazily created process runner.
     */
    private function getProcessRunner(ScriptEvent $event): ProcessRunner
    {
        if ($this->processRunner === null) {
            $this->processRunner = new ComposerProcessRunner($event->getIO());
        }

        return $this->processRunner;
    }

    /**
     * Builds a cache key for exec-mode service startup.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that identifies the service.
     *
     * @return string
     *   Returns a stable serialized key for the service startup command.
     */
    private function getExecServiceStartupKey(DockerComposerConfig $config): string
    {
        return serialize([
            $config->getService(),
            $config->getComposeFiles(),
            $config->getProjectDirectory(),
        ]);
    }

    /**
     * Throws a Composer script exception for a failed Docker command.
     *
     * @param  ProcessRunner  $runner
     *   The runner that contains the latest process error output.
     *
     * @param  int  $exitCode
     *   The process exit code returned by Docker Compose.
     *
     * @param  string  $phase
     *   The Docker Compose phase that failed.
     *
     * @param  list<string>  $command
     *   The Docker Compose command arguments that failed.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Always thrown with the formatted command failure.
     */
    private function throwScriptExecutionException(ProcessRunner $runner, int $exitCode, string $phase, array $command): void
    {
        $message = sprintf(
            "Docker Compose %s command failed with exit code %d.\nCommand: %s",
            $phase,
            $exitCode,
            $this->formatCommand($command),
        );

        $errorOutput = trim($runner->getErrorOutput());
        if ($errorOutput !== '') {
            $message .= "\nError Output: " . $errorOutput;
        }

        throw new ScriptExecutionException($message, $exitCode);
    }

    /**
     * Formats command arguments for shell output.
     *
     * @param  list<string>  $command
     *   The raw command arguments to escape.
     *
     * @return string
     *   Returns a shell-escaped command line for diagnostics.
     */
    private function formatCommand(array $command): string
    {
        return implode(' ', array_map([ProcessExecutor::class, 'escape'], $command));
    }

    /**
     * Checks whether a script event was triggered by another Composer event.
     *
     * @param  ScriptEvent  $event
     *   The script event to inspect.
     *
     * @return bool
     *   Returns `true` when __event__ is nested under another Composer event.
     */
    private function isNestedScript(ScriptEvent $event): bool
    {
        return $event->getOriginatingEvent() instanceof Event;
    }

    /**
     * Checks whether environment settings disable Docker redirection.
     *
     * @return bool
     *   Returns `true` when `DOCKER_COMPOSER_DISABLE` is truthy.
     */
    private function isDisabledByEnvironment(): bool
    {
        $value = getenv('DOCKER_COMPOSER_DISABLE');

        return $value !== false && $value !== '' && $value !== '0';
    }
}
