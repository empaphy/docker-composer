<?php

/**
 * Defines environment-based container detection.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Detects container execution from environment variables and runtime files.
 */
final class EnvironmentContainerDetector implements ContainerDetector
{
    /**
     * Reads environment variables.
     *
     * @var callable(string): (string|false)
     *   Returns an environment value or `false` when absent.
     */
    private $getEnv;

    /**
     * Checks whether a filesystem path exists.
     *
     * @var callable(string): bool
     *   Returns `true` when the path exists.
     */
    private $fileExists;

    /**
     * Reads file contents.
     *
     * @var callable(string): (string|false)
     *   Returns file contents or `false` when unreadable.
     */
    private $fileGetContents;

    /**
     * Creates an environment-based container detector.
     *
     * @param  (callable(string): (string|false))|null  $getEnv
     *   The environment reader, or `null` for `getenv`.
     *
     * @param  (callable(string): bool)|null  $fileExists
     *   The path existence checker, or `null` for `file_exists`.
     *
     * @param  (callable(string): (string|false))|null  $fileGetContents
     *   The file reader, or `null` for `file_get_contents`.
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

    /**
     * Checks whether the current process runs inside a container.
     *
     * @return bool
     *   Returns `true` when a container marker is detected.
     */
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
