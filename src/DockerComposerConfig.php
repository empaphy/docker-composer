<?php

/**
 * Defines Docker Composer configuration parsing.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\Composer;
use InvalidArgumentException;
use LogicException;

/**
 * Parses and exposes Docker Composer configuration from Composer metadata.
 */
final class DockerComposerConfig
{
    /**
     * Names the Composer extra key used by this plugin.
     *
     * @var string
     *   Stores the `extra` object key containing Docker Composer settings.
     */
    public const EXTRA_KEY = 'docker-composer';

    /**
     * Selects Docker Compose exec mode.
     *
     * @var string
     *   Stores the mode that executes scripts in an existing service container.
     */
    public const MODE_EXEC = 'exec';

    /**
     * Selects Docker Compose run mode.
     *
     * @var string
     *   Stores the mode that creates a one-off service container for scripts.
     */
    public const MODE_RUN = 'run';

    /**
     * Lists supported configuration keys.
     *
     * @var list<string>
     *   Stores accepted keys from the `extra.docker-composer` object.
     */
    private const KNOWN_KEYS = [
        'service',
        'mode',
        'compose-files',
        'project-directory',
        'workdir',
        'exclude',
    ];

    /**
     * Stores the Docker Compose service name.
     */
    private ?string $service;

    /**
     * Stores the Docker Compose invocation mode.
     */
    private string $mode;

    /**
     * Stores extra Docker Compose file arguments.
     *
     * @var list<string>
     */
    private array $composeFiles;

    /**
     * Stores the Docker Compose project directory.
     */
    private ?string $projectDirectory;

    /**
     * Stores the service working directory.
     */
    private ?string $workdir;

    /**
     * Stores script names excluded from Docker redirection.
     *
     * @var list<string>
     *   Stores Composer script names that should run on the host.
     */
    private array $exclude;

    /**
     * Stores unknown configuration keys.
     *
     * @var list<string>
     *   Stores keys kept for warning output.
     */
    private array $unknownKeys;

    /**
     * Creates an immutable configuration value.
     *
     * @param  string|null  $service
     *   The Docker Compose service name, or `null` when missing.
     *
     * @param  string  $mode
     *   The Docker Compose mode, either `"exec"` or `"run"`.
     *
     * @param  list<string>  $composeFiles
     *   The Docker Compose files passed with `--file`.
     *
     * @param  string|null  $projectDirectory
     *   The Docker Compose project directory, or `null` for the default.
     *
     * @param  string|null  $workdir
     *   The service working directory, or `null` for the service default.
     *
     * @param  list<string>  $exclude
     *   The Composer script names that should not be redirected.
     *
     * @param  list<string>  $unknownKeys
     *   The unrecognized config keys retained for warnings.
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

    /**
     * Creates configuration from Composer package metadata.
     *
     * @param  Composer  $composer
     *   The Composer instance that owns the package metadata.
     *
     * @return self
     *   Returns parsed Docker Composer configuration.
     *
     * @throws InvalidArgumentException
     *   Thrown when `extra.docker-composer` has an invalid shape or value.
     */
    public static function fromComposer(Composer $composer): self
    {
        $extra = $composer->getPackage()->getExtra();
        if (! array_key_exists(self::EXTRA_KEY, $extra)) {
            return self::empty();
        }

        $raw = $extra[self::EXTRA_KEY];
        if (! is_array($raw)) {
            throw new InvalidArgumentException('extra.docker-composer must be an object.');
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

    /**
     * Checks whether a Docker Compose service is configured.
     *
     * @return bool
     *   Returns `true` when a service name is available.
     */
    public function isConfigured(): bool
    {
        return $this->service !== null;
    }

    /**
     * Gets the configured Docker Compose service name.
     *
     * @return string
     *   Returns the non-empty service name.
     *
     * @throws LogicException
     *   Thrown when the service is requested before configuration exists.
     */
    public function getService(): string
    {
        if ($this->service === null) {
            throw new LogicException('Docker Compose service is not configured.');
        }

        return $this->service;
    }

    /**
     * Gets the configured Docker Compose mode.
     *
     * @return string
     *   Returns `"exec"` or `"run"`.
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Gets configured Docker Compose file paths.
     *
     * @return list<string>
     *   Returns paths passed to Docker Compose with `--file`.
     */
    public function getComposeFiles(): array
    {
        return $this->composeFiles;
    }

    /**
     * Gets the configured Docker Compose project directory.
     *
     * @return string|null
     *   Returns the directory path, or `null` for Docker Compose defaults.
     */
    public function getProjectDirectory(): ?string
    {
        return $this->projectDirectory;
    }

    /**
     * Gets the configured service working directory.
     *
     * @return string|null
     *   Returns the service working directory, or `null` for service defaults.
     */
    public function getWorkdir(): ?string
    {
        return $this->workdir;
    }

    /**
     * Checks whether a Composer script is excluded.
     *
     * @param  string  $scriptName
     *   The Composer script name to check.
     *
     * @return bool
     *   Returns `true` when __scriptName__ should run on the host.
     */
    public function isExcluded(string $scriptName): bool
    {
        return in_array($scriptName, $this->exclude, true);
    }

    /**
     * Gets unrecognized configuration keys.
     *
     * @return list<string>
     *   Returns keys ignored by the plugin after warning output.
     */
    public function getUnknownKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * Creates an empty configuration object.
     *
     * @return self
     *   Returns configuration that leaves scripts on the host.
     */
    private static function empty(): self
    {
        return new self(null, self::MODE_EXEC, [], null, null, [], []);
    }

    /**
     * Normalizes a decoded Composer object.
     *
     * @param  array<mixed>  $raw
     *   The raw value decoded from Composer `extra` metadata.
     *
     * @return array<string, mixed>
     *   Returns __raw__ with verified `string` keys.
     *
     * @throws InvalidArgumentException
     *   Thrown when __raw__ contains non-`string` keys.
     */
    private static function object(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('extra.docker-composer must be an object.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Reads an optional non-empty `string` value.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration object.
     *
     * @param  string  $key
     *   The configuration key to read.
     *
     * @return string|null
     *   Returns the configured `string`, or `null` when omitted.
     *
     * @throws InvalidArgumentException
     *   Thrown when __key__ exists but is not a non-empty `string`.
     */
    private static function optionalString(array $raw, string $key): ?string
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        if (! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidArgumentException(sprintf('extra.docker-composer.%s must be a non-empty string.', $key));
        }

        return $raw[$key];
    }

    /**
     * Reads the configured Docker Compose mode.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration object.
     *
     * @return string
     *   Returns `"exec"` when omitted, otherwise `"exec"` or `"run"`.
     *
     * @throws InvalidArgumentException
     *   Thrown when `mode` is not an accepted `string` value.
     */
    private static function mode(array $raw): string
    {
        if (! array_key_exists('mode', $raw)) {
            return self::MODE_EXEC;
        }

        if (! is_string($raw['mode']) || ! in_array($raw['mode'], [self::MODE_EXEC, self::MODE_RUN], true)) {
            throw new InvalidArgumentException('extra.docker-composer.mode must be "exec" or "run".');
        }

        return $raw['mode'];
    }

    /**
     * Reads Docker Compose file settings.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration object.
     *
     * @return list<string>
     *   Returns file paths configured by `compose-files`.
     *
     * @throws InvalidArgumentException
     *   Thrown when `compose-files` is not a non-empty `string` or list.
     */
    private static function composeFiles(array $raw): array
    {
        if (! array_key_exists('compose-files', $raw) || $raw['compose-files'] === null) {
            return [];
        }

        if (is_string($raw['compose-files'])) {
            if ($raw['compose-files'] === '') {
                throw new InvalidArgumentException('extra.docker-composer.compose-files must contain non-empty strings.');
            }

            return [$raw['compose-files']];
        }

        return self::stringList($raw, 'compose-files');
    }

    /**
     * Reads a list of non-empty `string` values.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration object.
     *
     * @param  string  $key
     *   The configuration key to read.
     *
     * @return list<string>
     *   Returns the configured list, or an empty list when omitted.
     *
     * @throws InvalidArgumentException
     *   Thrown when __key__ is not a list of non-empty `string` values.
     */
    private static function stringList(array $raw, string $key): array
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return [];
        }

        if (! is_array($raw[$key]) || ! array_is_list($raw[$key])) {
            throw new InvalidArgumentException(sprintf('extra.docker-composer.%s must be a list of strings.', $key));
        }

        $values = [];
        foreach ($raw[$key] as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('extra.docker-composer.%s must contain only non-empty strings.', $key));
            }

            $values[] = $value;
        }

        return $values;
    }
}
