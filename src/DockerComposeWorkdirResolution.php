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
 * Stores inferred container workdir and host project path mapping.
 */
final class DockerComposeWorkdirResolution
{
    /**
     * Creates resolved workdir metadata.
     *
     * @param  string|null  $workdir
     *   The container working directory, or `null` when unavailable.
     *
     * @param  string|null  $containerProjectRoot
     *   The container path matching the host project root, or `null`.
     */
    public function __construct(
        private readonly ?string $workdir,
        private readonly ?string $containerProjectRoot,
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
     * Gets the container project root mapping.
     *
     * @return string|null
     *   Returns the container path matching the host project root, or `null`.
     */
    public function getContainerProjectRoot(): ?string
    {
        return $this->containerProjectRoot;
    }

    /**
     * Checks whether host project paths can be translated.
     *
     * @return bool
     *   Returns `true` when the host project root has a container path.
     */
    public function hasPathMapping(): bool
    {
        return $this->containerProjectRoot !== null;
    }
}
