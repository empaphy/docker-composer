<?php

/**
 * Defines Docker Compose execution options.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Exposes service-level options needed to run Docker Compose commands.
 */
interface DockerComposeOptions
{
    /**
     * Selects Docker Compose exec mode.
     *
     * @var string
     *   Stores the mode that executes commands in an existing service.
     */
    public const MODE_EXEC = 'exec';

    /**
     * Selects Docker Compose run mode.
     *
     * @var string
     *   Stores the mode that creates a one-off service container.
     */
    public const MODE_RUN = 'run';

    /**
     * Gets the configured Docker Compose service name.
     *
     * @return string
     *   Returns the non-empty service name.
     */
    public function getService(): string;

    /**
     * Gets the configured Docker Compose mode.
     *
     * @return string
     *   Returns `"exec"` or `"run"`.
     */
    public function getMode(): string;

    /**
     * Gets configured Docker Compose file paths.
     *
     * @return list<string>
     *   Returns paths passed to Docker Compose with `--file`.
     */
    public function getComposeFiles(): array;

    /**
     * Gets the configured Docker Compose project directory.
     *
     * @return string|null
     *   Returns the directory path, or `null` for Docker Compose defaults.
     */
    public function getProjectDirectory(): ?string;

    /**
     * Gets the configured service working directory.
     *
     * @return string|null
     *   Returns the service working directory, or `null` for service default.
     */
    public function getWorkdir(): ?string;
}
