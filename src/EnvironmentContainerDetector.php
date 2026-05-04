<?php

/**
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

final class EnvironmentContainerDetector implements ContainerDetector
{
    public function isInsideContainer(): bool
    {
        $marker = getenv('DOCKER_COMPOSER_INSIDE');
        if ($marker !== false && $marker !== '' && $marker !== '0') {
            return true;
        }

        if (file_exists('/.dockerenv') || file_exists('/run/.containerenv')) {
            return true;
        }

        $cgroup = @file_get_contents('/proc/1/cgroup');
        if ($cgroup === false) {
            return false;
        }

        return str_contains($cgroup, 'docker')
            || str_contains($cgroup, 'kubepods')
            || str_contains($cgroup, 'containerd')
            || str_contains($cgroup, 'libpod');
    }
}
