<?php

/**
 * Defines Docker Compose service execution.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Runs Docker Compose commands and prepares exec-mode services.
 */
final class DockerComposeRunner
{
    /**
     * Tracks services started for Docker Compose exec mode.
     *
     * @var array<string, true>
     */
    private array $startedExecServices = [];

    /**
     * Creates a Docker Compose runner.
     *
     * @param  ProcessRunner  $processRunner
     *   The process runner used for Docker Compose commands.
     *
     * @param  DockerComposeCommandBuilder  $commandBuilder
     *   The command builder used for service startup and status checks.
     */
    public function __construct(
        private readonly ProcessRunner $processRunner,
        private readonly DockerComposeCommandBuilder $commandBuilder,
    ) {}

    /**
     * Runs a prepared Docker Compose command.
     *
     * @param  DockerComposeOptions  $config
     *   The Docker Compose options for the target service.
     *
     * @param  list<string>  $command
     *   The full Docker Compose command to execute.
     *
     * @param  bool  $interactive
     *   Whether TTY passthrough should be requested.
     *
     * @return DockerComposeExecutionResult
     *   Returns the completed execution result.
     */
    public function run(DockerComposeOptions $config, array $command, bool $interactive): DockerComposeExecutionResult
    {
        if ($config->getMode() === DockerComposeOptions::MODE_EXEC) {
            $startup = $this->ensureExecServiceStarted($config);
            if ($startup !== null && ! $startup->isSuccessful()) {
                return $startup;
            }
        }

        $exitCode = $this->processRunner->run($command, $interactive);

        return new DockerComposeExecutionResult($config->getMode(), $command, $exitCode);
    }

    /**
     * Ensures the configured service can receive `docker compose exec`.
     *
     * @param  DockerComposeOptions  $config
     *   The Docker Compose options that identify the service.
     *
     * @return DockerComposeExecutionResult|null
     *   Returns a failed startup result, or `null` when startup is complete.
     */
    public function ensureExecServiceStarted(DockerComposeOptions $config): ?DockerComposeExecutionResult
    {
        $startupKey = $this->getExecServiceStartupKey($config);
        if (isset($this->startedExecServices[$startupKey])) {
            return null;
        }

        if ($this->isExecServiceRunning($config)) {
            $this->startedExecServices[$startupKey] = true;

            return null;
        }

        $upCommand = $this->commandBuilder->buildUpCommand($config);
        $exitCode = $this->processRunner->run($upCommand);
        if ($exitCode === 0) {
            $this->startedExecServices[$startupKey] = true;
        }

        return new DockerComposeExecutionResult('up', $upCommand, $exitCode);
    }

    /**
     * Checks whether the configured exec-mode service is running.
     *
     * @param  DockerComposeOptions  $config
     *   The Docker Compose options that identify the service.
     *
     * @return bool
     *   Returns `true` when Docker Compose lists the service as running.
     */
    private function isExecServiceRunning(DockerComposeOptions $config): bool
    {
        if (! $this->processRunner instanceof OutputCapturingProcessRunner) {
            return false;
        }

        $command = $this->commandBuilder->buildRunningServicesCommand($config);
        $output = '';
        if ($this->processRunner->runWithOutput($command, $output) !== 0) {
            return false;
        }

        $services = preg_split('/\R/', trim($output)) ?: [];
        foreach ($services as $service) {
            if (trim($service) === $config->getService()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds a cache key for exec-mode service startup.
     *
     * @param  DockerComposeOptions  $config
     *   The Docker Compose options that identify the service.
     *
     * @return string
     *   Returns a stable serialized key for the service startup command.
     */
    private function getExecServiceStartupKey(DockerComposeOptions $config): string
    {
        return serialize([
            $config->getService(),
            $config->getComposeFiles(),
            $config->getProjectDirectory(),
        ]);
    }
}
