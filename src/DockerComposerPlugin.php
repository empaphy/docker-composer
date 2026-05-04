<?php

/**
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

/**
 * Redirects Composer scripts into a configured Docker Compose service.
 */
class DockerComposerPlugin implements EventSubscriberInterface, PluginInterface
{
    private ?IOInterface $io = null;

    private ?DockerComposerConfig $config = null;

    private ?ProcessRunner $processRunner;

    private ContainerDetector $containerDetector;

    private DockerComposeCommandBuilder $commandBuilder;

    private bool $missingConfigWarningWritten = false;

    private bool $unknownConfigWarningWritten = false;

    /** @var array<string, true> */
    private array $startedExecServices = [];

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
     * Apply plugin modifications to Composer
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
     * Remove any hooks from Composer
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $dispatcher = $composer->getEventDispatcher();
        if ((new \ReflectionObject($dispatcher))->hasMethod('removeListener')) {
            $dispatcher->removeListener($this);
        }
    }

    /**
     * Prepare the plugin to be uninstalled
     *
     * This will be called after deactivate.
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
     *   The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [];
    }

    public function onScript(ScriptEvent $event): void
    {
        if ($this->isNestedScript($event) || $this->isDisabledByEnvironment() || $this->containerDetector->isInsideContainer()) {
            return;
        }

        $config = $this->getConfig($event);
        if ($config->isExcluded($event->getName())) {
            return;
        }

        if (! $config->isConfigured()) {
            $this->writeMissingConfigWarning($event->getIO());

            return;
        }

        $this->writeRedirectNotice($event, $config);
        $this->runInDocker($event, $config);
        $event->stopPropagation();
    }

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

    private function getConfig(ScriptEvent $event): DockerComposerConfig
    {
        if ($this->config === null) {
            $this->config = DockerComposerConfig::fromComposer($event->getComposer());
        }

        return $this->config;
    }

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

    private function writeMissingConfigWarning(IOInterface $io): void
    {
        if ($this->missingConfigWarningWritten) {
            return;
        }

        $io->writeError(
            '<warning>docker-composer: extra.docker-composer.service is not configured; running Composer scripts on the host.</warning>',
        );
        $this->missingConfigWarningWritten = true;
    }

    private function writeRedirectNotice(ScriptEvent $event, DockerComposerConfig $config): void
    {
        $event->getIO()->writeError(sprintf(
            '<info>docker-composer:</info> Running <comment>%s</comment> in Docker Compose service <comment>%s</comment>.',
            $event->getName(),
            $config->getService(),
        ));
    }

    private function runInDocker(ScriptEvent $event, DockerComposerConfig $config): void
    {
        $runner = $this->getProcessRunner($event);

        if ($config->getMode() === DockerComposerConfig::MODE_EXEC) {
            $startupKey = $this->getExecServiceStartupKey($config);
            if (! isset($this->startedExecServices[$startupKey])) {
                $exitCode = $runner->run($this->commandBuilder->buildUpCommand($config));
                if ($exitCode !== 0) {
                    $this->throwScriptExecutionException($runner, $exitCode);
                }

                $this->startedExecServices[$startupKey] = true;
            }
        }

        $isInteractive = $event->getIO()->isInteractive() && $runner->supportsTty();
        $exitCode = $runner->run($this->commandBuilder->buildScriptCommand(
            $config,
            $event,
            $isInteractive,
        ), $isInteractive);
        if ($exitCode !== 0) {
            $this->throwScriptExecutionException($runner, $exitCode);
        }
    }

    private function getProcessRunner(ScriptEvent $event): ProcessRunner
    {
        if ($this->processRunner === null) {
            $this->processRunner = new ComposerProcessRunner($event->getIO());
        }

        return $this->processRunner;
    }

    private function getExecServiceStartupKey(DockerComposerConfig $config): string
    {
        return serialize([
            $config->getService(),
            $config->getComposeFiles(),
            $config->getProjectDirectory(),
        ]);
    }

    private function throwScriptExecutionException(ProcessRunner $runner, int $exitCode): void
    {
        $errorOutput = $runner->getErrorOutput();
        $message = $errorOutput === ''
            ? 'Docker Compose command failed.'
            : 'Error Output: ' . $errorOutput;

        throw new ScriptExecutionException($message, $exitCode);
    }

    private function isNestedScript(ScriptEvent $event): bool
    {
        return $event->getOriginatingEvent() instanceof Event;
    }

    private function isDisabledByEnvironment(): bool
    {
        $value = getenv('DOCKER_COMPOSER_DISABLE');

        return $value !== false && $value !== '' && $value !== '0';
    }
}
