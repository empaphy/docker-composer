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
    /** @var callable(string): (string|false) */
    private $getEnv;

    /** @var callable(string): bool */
    private $fileExists;

    /** @var callable(string): (string|false) */
    private $fileGetContents;

    /**
     * @param null|callable(string): (string|false) $getEnv
     * @param null|callable(string): bool           $fileExists
     * @param null|callable(string): (string|false) $fileGetContents
     */
    public function __construct(
        ?callable $getEnv = null,
        ?callable $fileExists = null,
        ?callable $fileGetContents = null,
    ) {
        $this->getEnv = $getEnv ?? static fn(string $name): string|false => getenv($name);
        $this->fileExists = $fileExists ?? static fn(string $path): bool => file_exists($path);
        $this->fileGetContents = $fileGetContents ?? static fn(string $path): string|false => @file_get_contents($path);
    }

    public function isInsideContainer(): bool
    {
        $marker = ($this->getEnv)('DOCKER_COMPOSER_INSIDE');
        if ($marker !== false && $marker !== '' && $marker !== '0') {
            return true;
        }

        if (($this->fileExists)('/.dockerenv') || ($this->fileExists)('/run/.containerenv')) {
            return true;
        }

        $cgroup = ($this->fileGetContents)('/proc/1/cgroup');
        if ($cgroup === false) {
            return false;
        }

        return str_contains($cgroup, 'docker')
            || str_contains($cgroup, 'kubepods')
            || str_contains($cgroup, 'containerd')
            || str_contains($cgroup, 'libpod');
    }
}
