<?php

/**
 * Defines Docker Compose options with resolved workdir.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Wraps Docker Compose options with an inferred working directory.
 */
final class DockerComposeResolvedOptions implements DockerComposeOptions
{
    /**
     * Creates resolved Docker Compose options.
     *
     * @param  DockerComposeOptions  $options
     *   The source options.
     *
     * @param  string|null  $workdir
     *   The resolved working directory, or `null`.
     */
    public function __construct(
        private readonly DockerComposeOptions $options,
        private readonly ?string $workdir,
    ) {}

    /**
     * Gets the configured Docker Compose service name.
     *
     * @return string
     *   Returns the non-empty service name.
     */
    public function getService(): string
    {
        return $this->options->getService();
    }

    /**
     * Gets the configured Docker Compose mode.
     *
     * @return string
     *   Returns `"exec"` or `"run"`.
     */
    public function getMode(): string
    {
        return $this->options->getMode();
    }

    /**
     * Gets configured Docker Compose file paths.
     *
     * @return list<string>
     *   Returns paths passed to Docker Compose with `--file`.
     */
    public function getComposeFiles(): array
    {
        return $this->options->getComposeFiles();
    }

    /**
     * Gets the configured Docker Compose project directory.
     *
     * @return string|null
     *   Returns the directory path, or `null` for Docker Compose defaults.
     */
    public function getProjectDirectory(): ?string
    {
        return $this->options->getProjectDirectory();
    }

    /**
     * Gets the resolved service working directory.
     *
     * @return string|null
     *   Returns the resolved service workdir, or `null`.
     */
    public function getWorkdir(): ?string
    {
        return $this->workdir;
    }
}
