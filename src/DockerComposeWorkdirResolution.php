<?php

/**
 * Defines resolved Docker Compose workdir metadata.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Stores inferred container workdir and host directory mapping.
 */
final class DockerComposeWorkdirResolution
{
    /**
     * Creates resolved workdir metadata.
     *
     * @param  string|null  $workdir
     *   The container working directory, or `null` when unavailable.
     *
     * @param  string|null  $containerWorkingDirectory
     *   The container path matching the host working directory, or `null`.
     */
    public function __construct(
        private readonly ?string $workdir,
        private readonly ?string $containerWorkingDirectory,
    ) {}

    /**
     * Gets the resolved container workdir.
     *
     * @return string|null
     *   Returns the container working directory, or `null`.
     */
    public function getWorkdir(): ?string
    {
        return $this->workdir;
    }

    /**
     * Gets the container working directory mapping.
     *
     * @return string|null
     *   Returns the container path matching the host working directory, or `null`.
     */
    public function getContainerWorkingDirectory(): ?string
    {
        return $this->containerWorkingDirectory;
    }

    /**
     * Gets the legacy container project root mapping.
     *
     * @return string|null
     *   Returns the container path matching the host directory, or `null`.
     *
     * @deprecated Use {@see getContainerWorkingDirectory()} instead.
     */
    public function getContainerProjectRoot(): ?string
    {
        return $this->containerWorkingDirectory;
    }

    /**
     * Checks whether host paths can be translated.
     *
     * @return bool
     *   Returns `true` when the host directory has a container path.
     */
    public function hasPathMapping(): bool
    {
        return $this->containerWorkingDirectory !== null;
    }
}
