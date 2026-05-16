<?php

/**
 * Defines Docker Compose execution results.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Describes the completed Docker Compose phase and exit code.
 */
final class DockerComposeExecutionResult
{
    /**
     * Creates a Docker Compose execution result.
     *
     * @param  string  $phase
     *   The Docker Compose phase that ran, such as `"up"` or `"exec"`.
     *
     * @param  list<string>  $command
     *   The Docker Compose command arguments that were executed.
     *
     * @param  int  $exitCode
     *   The process exit code returned by Docker Compose.
     */
    public function __construct(
        private readonly string $phase,
        private readonly array $command,
        private readonly int $exitCode,
    ) {}

    /**
     * Checks whether Docker Compose completed successfully.
     *
     * @return bool
     *   Returns `true` when the exit code is zero.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Gets the Docker Compose phase that ran.
     *
     * @return string
     *   Returns the phase name.
     */
    public function getPhase(): string
    {
        return $this->phase;
    }

    /**
     * Gets the executed Docker Compose command.
     *
     * @return list<string>
     *   Returns command arguments.
     */
    public function getCommand(): array
    {
        return $this->command;
    }

    /**
     * Gets the Docker Compose exit code.
     *
     * @return int
     *   Returns the process exit code.
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
