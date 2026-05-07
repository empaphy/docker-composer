<?php

/**
 * Defines the container detector contract.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Detects whether the current Composer process is already containerized.
 */
interface ContainerDetector
{
    /**
     * Checks the current container execution state.
     *
     * @return bool
     *   Returns `true` when the process appears to run inside a container.
     */
    public function isInsideContainer(): bool;
}
