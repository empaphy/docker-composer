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
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Redirects Composer scripts into a configured Docker Compose service.
 */
class DockerComposerPlugin implements EventSubscriberInterface, PluginInterface
{
    /**
     * Lists Composer commands redirected before host execution.
     *
     * @var list<string>
     */
    private const REDIRECTED_COMMANDS = [
        'install',
        'update',
        'require',
        'remove',
        'reinstall',
    ];

    /**
     * Stores Composer IO for plugin messages.
     */
    private ?IOInterface $io = null;

    /**
     * Stores parsed Docker-Composer configuration.
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
     * Runs Docker Compose commands for the active process runner.
     */
    private ?DockerComposeRunner $dockerRunner = null;

    /**
     * Resolves container workdir and host directory mapping.
     */
    private DockerComposeWorkdirResolver $workdirResolver;

    /**
     * Tracks whether the missing configuration warning was written.
     */
    private bool $missingConfigWarningWritten = false;

    /**
     * Tracks whether unknown configuration warnings were written.
     */
    private bool $unknownConfigWarningWritten = false;

    /**
     * Tracks whether duplicate service mapping warnings were written.
     */
    private bool $duplicateServiceMappingWarningsWritten = false;

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
     *
     * @param  DockerComposeWorkdirResolver|null  $workdirResolver
     *   The workdir resolver, or `null` for the default resolver.
     */
    public function __construct(
        ?ProcessRunner $processRunner = null,
        ?ContainerDetector $containerDetector = null,
        ?DockerComposeCommandBuilder $commandBuilder = null,
        ?DockerComposeWorkdirResolver $workdirResolver = null,
    ) {
        $this->processRunner = $processRunner;
        $this->containerDetector = $containerDetector ?? new EnvironmentContainerDetector();
        $this->commandBuilder = $commandBuilder ?? new DockerComposeCommandBuilder();
        $this->workdirResolver = $workdirResolver ?? new DockerComposeWorkdirResolver($this->commandBuilder);
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
        $this->writeDuplicateServiceMappingWarnings($io);
        $this->registerCommandListener($composer);
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
        $this->writeDuplicateServiceMappingWarnings($event->getIO());

        if ($config->isExcluded($event->getName())) {
            return;
        }

        if (! $config->isConfiguredForScript($event->getName())) {
            $this->writeMissingConfigWarning($event->getIO(), $event->getName());

            return;
        }

        $scriptConfig = $config->forScript($event->getName());

        $this->writeRedirectNotice($event, $scriptConfig);
        $this->runInDocker($event, $scriptConfig);
        $event->stopPropagation();
    }

    /**
     * Redirects selected Composer commands into Docker Compose.
     *
     * @param  PreCommandRunEvent  $event
     *   The Composer command event to inspect and possibly redirect.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Thrown after Docker execution to stop host Composer command handling.
     */
    public function onCommand(PreCommandRunEvent $event): void
    {
        $commandName = $event->getCommand();
        if (! in_array($commandName, self::REDIRECTED_COMMANDS, true)) {
            return;
        }

        $io = $this->io;
        if ($io === null || $this->isDisabledByEnvironment() || $this->containerDetector->isInsideContainer()) {
            return;
        }

        $config = $this->config;
        if ($config === null) {
            return;
        }

        $this->writeDuplicateServiceMappingWarnings($io);

        if ($config->isExcluded($commandName)) {
            return;
        }

        if (! $config->isConfiguredForScript($commandName)) {
            $this->writeMissingConfigWarning($io, $commandName, 'command');

            return;
        }

        $commandConfig = $config->forScript($commandName);

        $this->writeCommandRedirectNotice($io, $commandName, $commandConfig);
        $this->runComposerCommandInDocker($event, $commandConfig);

        throw new ScriptExecutionException('', 0);
    }

    /**
     * Registers the listener for dependency Composer commands.
     *
     * @param  Composer  $composer
     *   The Composer instance whose commands should be watched.
     *
     * @return void
     *   Returns nothing.
     */
    private function registerCommandListener(Composer $composer): void
    {
        if (class_exists(PreCommandRunEvent::class) && defined(PluginEvents::class . '::PRE_COMMAND_RUN')) {
            $eventName = constant(PluginEvents::class . '::PRE_COMMAND_RUN');
            assert(is_string($eventName));

            $composer->getEventDispatcher()->addListener($eventName, [$this, 'onCommand'], PHP_INT_MAX);
        }
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
     *   Returns parsed Docker-Composer configuration.
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
     * Writes warnings for duplicate same-service script mappings.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeDuplicateServiceMappingWarnings(IOInterface $io): void
    {
        if ($this->duplicateServiceMappingWarningsWritten || $this->config === null) {
            return;
        }

        foreach ($this->config->getDuplicateServiceMappingScripts() as $duplicate) {
            $io->writeError(sprintf(
                '<warning>docker-composer: duplicate service-mapping script "%s" for service "%s" will be ignored.</warning>',
                OutputFormatter::escape($duplicate['script']),
                OutputFormatter::escape($duplicate['service']),
            ));
        }

        $this->duplicateServiceMappingWarningsWritten = true;
    }

    /**
     * Writes the missing service configuration warning.
     *
     * @param  IOInterface  $io
     *   The Composer IO that receives the warning.
     *
     * @param  string  $entryName
     *   The Composer script or command name without a configured service.
     *
     * @param  string  $entryType
     *   The Composer entry type being allowed to run on the host.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeMissingConfigWarning(IOInterface $io, string $entryName, string $entryType = 'script'): void
    {
        if ($this->missingConfigWarningWritten) {
            return;
        }

        $io->writeError(sprintf(
            '<warning>docker-composer: no default service and no service-mapping override for "%s"; running Composer %s on the host.</warning>',
            OutputFormatter::escape($entryName),
            $entryType,
        ));
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
            OutputFormatter::escape($event->getName()),
            OutputFormatter::escape($config->getService()),
        ));
    }

    /**
     * Writes the command redirection notice.
     *
     * @param  IOInterface  $io
     *   The Composer IO that receives the notice.
     *
     * @param  string  $commandName
     *   The Composer command being redirected.
     *
     * @param  DockerComposerConfig  $config
     *   The configuration that provides the target service.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeCommandRedirectNotice(IOInterface $io, string $commandName, DockerComposerConfig $config): void
    {
        $io->writeError(sprintf(
            '<info>docker-composer:</info> Running composer <comment>%s</comment> in Docker Compose service <comment>%s</comment>.',
            OutputFormatter::escape($commandName),
            OutputFormatter::escape($config->getService()),
        ));
    }

    /**
     * Runs a Composer script inside Docker Compose.
     *
     * @param  ScriptEvent  $event
     *   The Composer script event being executed.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker-Composer configuration used to build commands.
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
        $hostWorkingDirectory = $this->getHostWorkingDirectory();
        $resolution = $this->resolveDockerWorkdir($config, $hostWorkingDirectory, $runner);
        $config = new DockerComposeResolvedOptions($config, $resolution->getWorkdir());
        $isInteractive = $event->getIO()->isInteractive() && $runner->supportsTty();
        $scriptCommand = $this->commandBuilder->buildScriptCommand(
            $config,
            $event,
            $isInteractive,
            $hostWorkingDirectory,
            $resolution->getContainerWorkingDirectory(),
        );
        $this->runDockerCommand($runner, $config, $scriptCommand, $isInteractive);
    }

    /**
     * Runs a Composer command inside Docker Compose.
     *
     * @param  PreCommandRunEvent  $event
     *   The Composer command event being executed.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker-Composer configuration used to build commands.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Thrown when Docker Compose startup or command execution fails.
     */
    private function runComposerCommandInDocker(PreCommandRunEvent $event, DockerComposerConfig $config): void
    {
        $runner = $this->getProcessRunnerForCommand();
        $hostWorkingDirectory = $this->getHostWorkingDirectory();
        $resolution = $this->resolveDockerWorkdir($config, $hostWorkingDirectory, $runner);
        $config = new DockerComposeResolvedOptions($config, $resolution->getWorkdir());
        $isInteractive = $event->getInput()->isInteractive() && $runner->supportsTty();
        $command = $this->commandBuilder->buildComposerCommand(
            $config,
            $event->getCommand(),
            $event->getInput(),
            $isInteractive,
            $hostWorkingDirectory,
            $resolution->getContainerWorkingDirectory(),
        );
        $this->runDockerCommand($runner, $config, $command, $isInteractive);
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
     * Gets the process runner for Docker commands.
     *
     * @return ProcessRunner
     *   Returns the configured or lazily created process runner.
     */
    private function getProcessRunnerForCommand(): ProcessRunner
    {
        if ($this->processRunner === null) {
            if ($this->io === null) {
                throw new ScriptExecutionException('Docker-Composer plugin was not activated.', 1);
            }

            $this->processRunner = new ComposerProcessRunner($this->io);
        }

        return $this->processRunner;
    }

    /**
     * Resolves Docker Compose workdir metadata for execution.
     *
     * @param  DockerComposerConfig  $config
     *   The parsed Docker-Composer configuration.
     *
     * @param  string  $hostWorkingDirectory
     *   The active host working directory.
     *
     * @param  ProcessRunner  $runner
     *   The runner used for Docker commands.
     *
     * @return DockerComposeWorkdirResolution
     *   Returns inferred workdir and host directory mapping.
     */
    private function resolveDockerWorkdir(DockerComposerConfig $config, string $hostWorkingDirectory, ProcessRunner $runner): DockerComposeWorkdirResolution
    {
        return $this->workdirResolver->resolve($config, $hostWorkingDirectory, $runner, $this->getDockerRunner($runner));
    }

    /**
     * Gets the active host working directory.
     *
     * @return string
     *   Returns the process CWD, falling back to `"."`.
     */
    private function getHostWorkingDirectory(): string
    {
        $cwd = getcwd();

        return $cwd !== false ? $cwd : '.';
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
     * Runs a Docker Compose command and throws when it fails.
     *
     * @param  ProcessRunner  $runner
     *   The runner used for Docker Compose commands.
     *
     * @param  DockerComposeOptions  $config
     *   The Docker Compose options that identify the target service.
     *
     * @param  list<string>  $command
     *   The full Docker Compose command to execute.
     *
     * @param  bool  $interactive
     *   Whether TTY passthrough should be requested.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws ScriptExecutionException
     *   Thrown when Docker Compose startup or execution fails.
     */
    private function runDockerCommand(ProcessRunner $runner, DockerComposeOptions $config, array $command, bool $interactive): void
    {
        $result = $this->getDockerRunner($runner)->run($config, $command, $interactive);
        if (! $result->isSuccessful()) {
            $this->throwScriptExecutionException($runner, $result->getExitCode(), $result->getPhase(), $result->getCommand());
        }
    }

    /**
     * Gets the Docker Compose runner for the active process runner.
     *
     * @param  ProcessRunner  $runner
     *   The process runner used for Docker Compose commands.
     *
     * @return DockerComposeRunner
     *   Returns the shared Docker Compose runner.
     */
    private function getDockerRunner(ProcessRunner $runner): DockerComposeRunner
    {
        if ($this->dockerRunner === null) {
            $this->dockerRunner = new DockerComposeRunner($runner, $this->commandBuilder);
        }

        return $this->dockerRunner;
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
