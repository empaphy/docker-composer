<?php

/**
 * Defines Docker Compose workdir inference.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Resolves container workdir and host project path mapping.
 */
final class DockerComposeWorkdirResolver
{
    /**
     * Creates a Docker Compose workdir resolver.
     *
     * @param  DockerComposeCommandBuilder  $commandBuilder
     *   The builder used for discovery commands.
     */
    public function __construct(
        private readonly DockerComposeCommandBuilder $commandBuilder,
    ) {}

    /**
     * Resolves workdir and path mapping for a service.
     *
     * @param  DockerComposeOptions  $config
     *   The effective Docker Compose service options.
     *
     * @param  string  $hostProjectRoot
     *   The absolute project root on the host.
     *
     * @param  ProcessRunner|null  $processRunner
     *   The runner used for discovery commands, or `null` to skip them.
     *
     * @param  DockerComposeRunner|null  $dockerRunner
     *   The Docker Compose runner used to prepare exec probes.
     *
     * @return DockerComposeWorkdirResolution
     *   Returns inferred workdir and host project path mapping.
     */
    public function resolve(
        DockerComposeOptions $config,
        string $hostProjectRoot,
        ?ProcessRunner $processRunner = null,
        ?DockerComposeRunner $dockerRunner = null,
    ): DockerComposeWorkdirResolution {
        $workdir = $config->getWorkdir();
        $containerProjectRoot = null;
        $service = $processRunner instanceof OutputCapturingProcessRunner
            ? $this->readComposeService($config, $processRunner)
            : null;

        if ($service !== null) {
            $containerProjectRoot = $this->inferContainerProjectRoot($service, $hostProjectRoot);
            if ($containerProjectRoot !== null && $workdir === null) {
                $workdir = $containerProjectRoot;
            }

            $workdir ??= $this->readServiceWorkingDir($service);
        }

        if ($containerProjectRoot === null && $config->getWorkdir() !== null) {
            $containerProjectRoot = $config->getWorkdir();
        }

        if ($workdir === null && $processRunner instanceof OutputCapturingProcessRunner) {
            $workdir = $this->probeContainerWorkdir($config, $processRunner, $dockerRunner);
        }

        if ($workdir === null && $processRunner instanceof OutputCapturingProcessRunner && $service !== null) {
            $workdir = $this->inspectImageWorkdir($service, $processRunner);
        }

        return new DockerComposeWorkdirResolution($workdir, $containerProjectRoot);
    }

    /**
     * Reads the target service object from Docker Compose config.
     *
     * @param  DockerComposeOptions  $config
     *   The service options.
     *
     * @param  OutputCapturingProcessRunner  $processRunner
     *   The runner used to read Docker Compose config.
     *
     * @return array<string, mixed>|null
     *   Returns the service config object, or `null` when unavailable.
     */
    private function readComposeService(DockerComposeOptions $config, OutputCapturingProcessRunner $processRunner): ?array
    {
        $output = '';
        if ($processRunner->runWithOutput($this->commandBuilder->buildConfigCommand($config), $output) !== 0) {
            return null;
        }

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return null;
        }

        $services = $decoded['services'] ?? null;
        if (! is_array($services)) {
            return null;
        }

        $service = $services[$config->getService()] ?? null;

        return is_array($service) ? $service : null;
    }

    /**
     * Infers the container project root from service bind volumes.
     *
     * @param  array<string, mixed>  $service
     *   The Docker Compose service config object.
     *
     * @param  string  $hostProjectRoot
     *   The absolute project root on the host.
     *
     * @return string|null
     *   Returns the mapped container project root, or `null`.
     */
    private function inferContainerProjectRoot(array $service, string $hostProjectRoot): ?string
    {
        $volumes = $service['volumes'] ?? null;
        if (! is_array($volumes) || ! array_is_list($volumes)) {
            return null;
        }

        $hostProjectRoot = $this->normalizePath($hostProjectRoot);
        $bestSource = null;
        $bestTarget = null;

        foreach ($volumes as $volume) {
            if (! is_array($volume) || ($volume['type'] ?? null) !== 'bind') {
                continue;
            }

            $source = $volume['source'] ?? null;
            $target = $volume['target'] ?? null;
            if (! is_string($source) || $source === '' || ! is_string($target) || $target === '') {
                continue;
            }

            $source = $this->normalizePath($source);
            $target = $this->normalizePath($target);
            if ($source === $hostProjectRoot) {
                return $target;
            }

            if ($this->isPathAncestor($source, $hostProjectRoot) && ($bestSource === null || strlen($source) > strlen($bestSource))) {
                $bestSource = $source;
                $bestTarget = $target;
            }
        }

        if ($bestSource === null || $bestTarget === null) {
            return null;
        }

        return $this->appendPath($bestTarget, substr($hostProjectRoot, strlen($bestSource)));
    }

    /**
     * Reads a service-level Docker Compose working directory.
     *
     * @param  array<string, mixed>  $service
     *   The Docker Compose service config object.
     *
     * @return string|null
     *   Returns the configured `working_dir`, or `null`.
     */
    private function readServiceWorkingDir(array $service): ?string
    {
        $workingDir = $service['working_dir'] ?? null;

        return is_string($workingDir) && $workingDir !== '' ? $workingDir : null;
    }

    /**
     * Probes the service process for its default working directory.
     *
     * @param  DockerComposeOptions  $config
     *   The service options.
     *
     * @param  OutputCapturingProcessRunner  $processRunner
     *   The runner used for discovery commands.
     *
     * @param  DockerComposeRunner|null  $dockerRunner
     *   The Docker runner used to prepare exec services.
     *
     * @return string|null
     *   Returns the probed working directory, or `null`.
     */
    private function probeContainerWorkdir(
        DockerComposeOptions $config,
        OutputCapturingProcessRunner $processRunner,
        ?DockerComposeRunner $dockerRunner,
    ): ?string {
        if ($config->getMode() === DockerComposeOptions::MODE_EXEC) {
            if ($dockerRunner === null) {
                return null;
            }

            $startup = $dockerRunner->ensureExecServiceStarted($config);
            if ($startup !== null && ! $startup->isSuccessful()) {
                return null;
            }

            return $this->runWorkdirProbe($processRunner, $this->commandBuilder->buildExecWorkdirCommand($config));
        }

        return $this->runWorkdirProbe($processRunner, $this->commandBuilder->buildRunWorkdirCommand($config));
    }

    /**
     * Reads image default workdir from Docker image metadata.
     *
     * @param  array<string, mixed>  $service
     *   The Docker Compose service config object.
     *
     * @param  OutputCapturingProcessRunner  $processRunner
     *   The runner used for Docker image inspection.
     *
     * @return string|null
     *   Returns image `Config.WorkingDir`, or `null`.
     */
    private function inspectImageWorkdir(array $service, OutputCapturingProcessRunner $processRunner): ?string
    {
        $image = $service['image'] ?? null;
        if (! is_string($image) || $image === '') {
            return null;
        }

        return $this->runWorkdirProbe($processRunner, $this->commandBuilder->buildImageWorkdirCommand($image));
    }

    /**
     * Runs a command that prints one workdir path.
     *
     * @param  OutputCapturingProcessRunner  $processRunner
     *   The runner used for discovery commands.
     *
     * @param  list<string>  $command
     *   The command to execute.
     *
     * @return string|null
     *   Returns trimmed command output, or `null`.
     */
    private function runWorkdirProbe(OutputCapturingProcessRunner $processRunner, array $command): ?string
    {
        $output = '';
        if ($processRunner->runWithOutput($command, $output) !== 0) {
            return null;
        }

        $output = trim($output);

        return $output !== '' ? $output : null;
    }

    /**
     * Checks whether one path is an ancestor of another.
     *
     * @param  string  $ancestor
     *   The possible ancestor path.
     *
     * @param  string  $path
     *   The possible descendant path.
     *
     * @return bool
     *   Returns `true` when __path__ is below __ancestor__.
     */
    private function isPathAncestor(string $ancestor, string $path): bool
    {
        $prefix = $ancestor === '/' ? '/' : $ancestor . '/';

        return str_starts_with($path, $prefix);
    }

    /**
     * Appends a normalized suffix to a container path.
     *
     * @param  string  $base
     *   The base container path.
     *
     * @param  string  $suffix
     *   The host suffix being mapped into the container.
     *
     * @return string
     *   Returns a slash-separated container path.
     */
    private function appendPath(string $base, string $suffix): string
    {
        $suffix = ltrim(str_replace('\\', '/', $suffix), '/');
        if ($suffix === '') {
            return $base;
        }

        if ($base === '/') {
            return '/' . $suffix;
        }

        return $base . '/' . $suffix;
    }

    /**
     * Normalizes path separators and trailing slashes.
     *
     * @param  string  $path
     *   The path to normalize.
     *
     * @return string
     *   Returns a slash-separated path.
     */
    private function normalizePath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return $path === '' ? '/' : $path;
    }
}
