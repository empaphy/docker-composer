<?php

/**
 * Defines Laravel console Docker redirection.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer\Laravel;

use empaphy\docker_composer\ContainerDetector;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeResolvedOptions;
use empaphy\docker_composer\DockerComposeRunner;
use empaphy\docker_composer\DockerComposeWorkdirResolver;
use empaphy\docker_composer\ProcessRunner;

/**
 * Redirects Laravel console entries into Docker Compose.
 */
final class Redirector
{
    /**
     * Resolves container workdir and project path mapping.
     */
    private DockerComposeWorkdirResolver $workdirResolver;

    /**
     * Creates a Laravel console redirector.
     *
     * @param  DockerComposeRunner  $dockerRunner
     *   The shared Docker Compose runner.
     *
     * @param  DockerComposeCommandBuilder  $commandBuilder
     *   The shared Docker Compose command builder.
     *
     * @param  ContainerDetector  $containerDetector
     *   The detector for existing container execution.
     *
     * @param  ProcessRunner|null  $processRunner
     *   The process runner used for workdir discovery, or `null`.
     *
     * @param  DockerComposeWorkdirResolver|null  $workdirResolver
     *   The workdir resolver, or `null` for the default resolver.
     */
    public function __construct(
        private readonly DockerComposeRunner $dockerRunner,
        private readonly DockerComposeCommandBuilder $commandBuilder,
        private readonly ContainerDetector $containerDetector,
        private readonly ?ProcessRunner $processRunner = null,
        ?DockerComposeWorkdirResolver $workdirResolver = null,
    ) {
        $this->workdirResolver = $workdirResolver ?? new DockerComposeWorkdirResolver($this->commandBuilder);
    }

    /**
     * Redirects a Laravel console entry into Docker Compose when configured.
     *
     * @param  Config  $config
     *   The Laravel Docker configuration.
     *
     * @param  ConsoleEntry  $entry
     *   The Laravel console entry being redirected.
     *
     * @param  string  $projectRoot
     *   The absolute Laravel project root on the host.
     *
     * @param  bool  $interactive
     *   Whether interactive Docker execution is allowed.
     *
     * @return int|null
     *   Returns Docker exit code when redirected, or `null` for host execution.
     */
    public function redirect(Config $config, ConsoleEntry $entry, string $projectRoot, bool $interactive): ?int
    {
        if (! $config->isEnabled() || $this->isDisabledByEnvironment() || $this->containerDetector->isInsideContainer() || $config->excludes($entry)) {
            return null;
        }

        $effectiveConfig = $config->forEntry($entry);
        if ($effectiveConfig === null) {
            return null;
        }

        $resolution = $this->workdirResolver->resolve($effectiveConfig, $projectRoot, $this->processRunner, $this->dockerRunner);
        $effectiveOptions = new DockerComposeResolvedOptions($effectiveConfig, $resolution->getWorkdir());
        $arguments = $entry->getArguments();
        if ($resolution->hasPathMapping() && $resolution->getContainerProjectRoot() !== null) {
            $arguments = $this->absolutizeEntrypoint($arguments, $projectRoot);
            $arguments = $this->commandBuilder->translateProjectPaths($arguments, $projectRoot, $resolution->getContainerProjectRoot());
        }

        $command = $this->commandBuilder->buildProcessCommand($effectiveOptions, $arguments, $interactive);
        $result = $this->dockerRunner->run($effectiveOptions, $command, $interactive);

        return $result->getExitCode();
    }

    /**
     * Converts a project-relative PHP entrypoint to an absolute host path.
     *
     * @param  list<string>  $arguments
     *   The raw CLI arguments.
     *
     * @param  string  $projectRoot
     *   The absolute Laravel project root on the host.
     *
     * @return list<string>
     *   Returns arguments with an absolute first entrypoint when possible.
     */
    private function absolutizeEntrypoint(array $arguments, string $projectRoot): array
    {
        $entrypoint = $arguments[0] ?? null;
        if ($entrypoint === null || str_starts_with($entrypoint, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $entrypoint) === 1) {
            return $arguments;
        }

        $candidate = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entrypoint;
        if (is_file($candidate)) {
            $arguments[0] = $candidate;
        }

        return $arguments;
    }

    /**
     * Checks whether redirection is disabled by environment variable.
     *
     * @return bool
     *   Returns `true` when `DOCKER_COMPOSER_DISABLE` is truthy.
     */
    private function isDisabledByEnvironment(): bool
    {
        $value = getenv('DOCKER_COMPOSER_DISABLE');

        return $value !== false && $value !== '' && $value !== '0';
    }
}
