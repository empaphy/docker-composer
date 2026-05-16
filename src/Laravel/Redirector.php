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
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Redirects Laravel console entries into Docker Compose.
 */
final class Redirector
{
    /**
     * Resolves container workdir and host directory mapping.
     */
    private DockerComposeWorkdirResolver $workdirResolver;

    /**
     * Receives redirect notices before Docker execution begins.
     *
     * @var resource
     */
    private $errorOutput;

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
     *
     * @param  resource|null  $errorOutput
     *   The writable stream receiving redirect notices, or `null` for stderr.
     */
    public function __construct(
        private readonly DockerComposeRunner $dockerRunner,
        private readonly DockerComposeCommandBuilder $commandBuilder,
        private readonly ContainerDetector $containerDetector,
        private readonly ?ProcessRunner $processRunner = null,
        ?DockerComposeWorkdirResolver $workdirResolver = null,
        $errorOutput = null,
    ) {
        $this->workdirResolver = $workdirResolver ?? new DockerComposeWorkdirResolver($this->commandBuilder);
        if ($errorOutput === null) {
            /** @var resource $errorOutput */
            $errorOutput = fopen('php://stderr', 'w');
        }

        $this->errorOutput = $errorOutput;
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

        $this->writeRedirectNotice($entry, $effectiveConfig);

        $resolution = $this->workdirResolver->resolve($effectiveConfig, $projectRoot, $this->processRunner, $this->dockerRunner);
        $effectiveOptions = new DockerComposeResolvedOptions($effectiveConfig, $resolution->getWorkdir());
        $arguments = $entry->getArguments();
        if ($resolution->hasPathMapping() && $resolution->getContainerWorkingDirectory() !== null) {
            $arguments = $this->absolutizeEntrypoint($arguments, $projectRoot);
            $arguments = $this->commandBuilder->translateProjectPaths($arguments, $projectRoot, $resolution->getContainerWorkingDirectory());
        }

        $command = $this->commandBuilder->buildProcessCommand($effectiveOptions, $arguments, $interactive);
        $result = $this->dockerRunner->run($effectiveOptions, $command, $interactive);

        return $result->getExitCode();
    }

    /**
     * Writes a redirect notice to the configured error stream.
     *
     * @param  ConsoleEntry  $entry
     *   The Laravel console entry being redirected.
     *
     * @param  Config  $config
     *   The effective Docker configuration for the entry.
     *
     * @return void
     *   Returns nothing.
     */
    private function writeRedirectNotice(ConsoleEntry $entry, Config $config): void
    {
        $formatter = new OutputFormatter(false);

        fwrite($this->errorOutput, $formatter->format(sprintf(
            '<info>docker-composer:</info> Running <comment>%s</comment> in Docker Compose service <comment>%s</comment>.',
            OutputFormatter::escape($entry->getDisplayName()),
            OutputFormatter::escape($config->getService()),
        )) . PHP_EOL);
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
