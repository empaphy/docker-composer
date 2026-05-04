<?php

/**
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\Composer;

final class DockerComposerConfig
{
    public const EXTRA_KEY = 'docker-composer';
    public const MODE_EXEC = 'exec';
    public const MODE_RUN = 'run';

    private const KNOWN_KEYS = [
        'service',
        'mode',
        'compose-files',
        'project-directory',
        'workdir',
        'exclude',
    ];

    private ?string $service;

    private string $mode;

    /** @var list<string> */
    private array $composeFiles;

    private ?string $projectDirectory;

    private ?string $workdir;

    /** @var list<string> */
    private array $exclude;

    /** @var list<string> */
    private array $unknownKeys;

    /**
     * @param list<string> $composeFiles
     * @param list<string> $exclude
     * @param list<string> $unknownKeys
     */
    private function __construct(
        ?string $service,
        string $mode,
        array $composeFiles,
        ?string $projectDirectory,
        ?string $workdir,
        array $exclude,
        array $unknownKeys,
    ) {
        $this->service = $service;
        $this->mode = $mode;
        $this->composeFiles = $composeFiles;
        $this->projectDirectory = $projectDirectory;
        $this->workdir = $workdir;
        $this->exclude = $exclude;
        $this->unknownKeys = $unknownKeys;
    }

    public static function fromComposer(Composer $composer): self
    {
        $extra = $composer->getPackage()->getExtra();
        if (! array_key_exists(self::EXTRA_KEY, $extra)) {
            return self::empty();
        }

        $raw = $extra[self::EXTRA_KEY];
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('extra.docker-composer must be an object.');
        }

        $raw = self::object($raw);
        $unknownKeys = array_values(array_diff(array_keys($raw), self::KNOWN_KEYS));
        $service = self::optionalString($raw, 'service');
        $mode = self::mode($raw);
        $composeFiles = self::composeFiles($raw);
        $projectDirectory = self::optionalString($raw, 'project-directory');
        $workdir = self::optionalString($raw, 'workdir');
        $exclude = self::stringList($raw, 'exclude');

        return new self($service, $mode, $composeFiles, $projectDirectory, $workdir, $exclude, $unknownKeys);
    }

    public function isConfigured(): bool
    {
        return $this->service !== null;
    }

    public function getService(): string
    {
        if ($this->service === null) {
            throw new \LogicException('Docker Compose service is not configured.');
        }

        return $this->service;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * @return list<string>
     */
    public function getComposeFiles(): array
    {
        return $this->composeFiles;
    }

    public function getProjectDirectory(): ?string
    {
        return $this->projectDirectory;
    }

    public function getWorkdir(): ?string
    {
        return $this->workdir;
    }

    public function isExcluded(string $scriptName): bool
    {
        return in_array($scriptName, $this->exclude, true);
    }

    /**
     * @return list<string>
     */
    public function getUnknownKeys(): array
    {
        return $this->unknownKeys;
    }

    private static function empty(): self
    {
        return new self(null, self::MODE_EXEC, [], null, null, [], []);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function object(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('extra.docker-composer must be an object.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function optionalString(array $raw, string $key): ?string
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        if (! is_string($raw[$key]) || $raw[$key] === '') {
            throw new \InvalidArgumentException(sprintf('extra.docker-composer.%s must be a non-empty string.', $key));
        }

        return $raw[$key];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function mode(array $raw): string
    {
        if (! array_key_exists('mode', $raw)) {
            return self::MODE_EXEC;
        }

        if (! is_string($raw['mode']) || ! in_array($raw['mode'], [self::MODE_EXEC, self::MODE_RUN], true)) {
            throw new \InvalidArgumentException('extra.docker-composer.mode must be "exec" or "run".');
        }

        return $raw['mode'];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private static function composeFiles(array $raw): array
    {
        if (! array_key_exists('compose-files', $raw) || $raw['compose-files'] === null) {
            return [];
        }

        if (is_string($raw['compose-files'])) {
            if ($raw['compose-files'] === '') {
                throw new \InvalidArgumentException('extra.docker-composer.compose-files must contain non-empty strings.');
            }

            return [$raw['compose-files']];
        }

        return self::stringList($raw, 'compose-files');
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private static function stringList(array $raw, string $key): array
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return [];
        }

        if (! is_array($raw[$key]) || ! array_is_list($raw[$key])) {
            throw new \InvalidArgumentException(sprintf('extra.docker-composer.%s must be a list of strings.', $key));
        }

        $values = [];
        foreach ($raw[$key] as $value) {
            if (! is_string($value) || $value === '') {
                throw new \InvalidArgumentException(sprintf('extra.docker-composer.%s must contain only non-empty strings.', $key));
            }

            $values[] = $value;
        }

        return $values;
    }
}
