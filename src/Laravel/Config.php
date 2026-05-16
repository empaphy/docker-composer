<?php

/**
 * Defines Laravel Docker-Composer configuration parsing.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer\Laravel;

use empaphy\docker_composer\DockerComposeOptions;
use InvalidArgumentException;
use LogicException;

/**
 * Parses and exposes Laravel console Docker redirection configuration.
 */
final class Config implements DockerComposeOptions
{
    /**
     * Lists supported configuration keys.
     *
     * @var list<string>
     */
    private const KNOWN_KEYS = [
        'enabled',
        'service',
        'mode',
        'compose_files',
        'project_directory',
        'workdir',
        'exclude',
        'service_mapping',
    ];

    /**
     * Creates immutable Laravel Docker configuration.
     *
     * @param  bool  $enabled
     *   Whether Laravel console redirection is enabled.
     *
     * @param  string|null  $service
     *   The default Docker Compose service, or `null` when missing.
     *
     * @param  array<string, string>  $servicesByEntry
     *   Docker Compose services keyed by Laravel entry identifier.
     *
     * @param  string  $mode
     *   The Docker Compose mode, either `"exec"` or `"run"`.
     *
     * @param  list<string>  $composeFiles
     *   The Docker Compose files passed with `--file`.
     *
     * @param  string|null  $projectDirectory
     *   The Docker Compose project directory, or `null` for default.
     *
     * @param  string|null  $workdir
     *   The service working directory, or `null` for service default.
     *
     * @param  list<string>  $exclude
     *   The Laravel entries that should run on the host.
     */
    private function __construct(
        private readonly bool $enabled,
        private readonly ?string $service,
        private readonly array $servicesByEntry,
        private readonly string $mode,
        private readonly array $composeFiles,
        private readonly ?string $projectDirectory,
        private readonly ?string $workdir,
        private readonly array $exclude,
    ) {}

    /**
     * Creates configuration from a Laravel config array.
     *
     * @param  array<mixed>  $raw
     *   The raw `docker_composer` config array.
     *
     * @return self
     *   Returns parsed Laravel Docker configuration.
     *
     * @throws InvalidArgumentException
     *   Thrown when the config array has an invalid shape or value.
     */
    public static function fromArray(array $raw): self
    {
        $raw = self::object($raw);
        $unknownKeys = array_values(array_diff(array_keys($raw), self::KNOWN_KEYS));
        if ($unknownKeys !== []) {
            throw new InvalidArgumentException(sprintf('docker_composer contains unknown key "%s".', $unknownKeys[0]));
        }

        return new self(
            self::enabled($raw),
            self::optionalString($raw, 'service'),
            self::serviceMapping($raw),
            self::mode($raw),
            self::composeFiles($raw),
            self::optionalString($raw, 'project_directory'),
            self::optionalString($raw, 'workdir'),
            self::expandClassEntries(self::stringList($raw, 'exclude')),
        );
    }

    /**
     * Checks whether Laravel console redirection is enabled.
     *
     * @return bool
     *   Returns `true` when redirection should be considered.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
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
     *   Returns the service working directory, or `null` for service default.
     */
    public function getWorkdir(): ?string
    {
        return $this->workdir;
    }

    /**
     * Checks whether any console entry name is excluded.
     *
     * @param  ConsoleEntry  $entry
     *   The Laravel console entry to inspect.
     *
     * @return bool
     *   Returns `true` when one entry name is excluded.
     */
    public function excludes(ConsoleEntry $entry): bool
    {
        foreach ($entry->getNames() as $name) {
            if (in_array($name, $this->exclude, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates a copy configured for the matched console entry service.
     *
     * @param  ConsoleEntry  $entry
     *   The Laravel console entry to match.
     *
     * @return self|null
     *   Returns service-specific config, or `null` when no service applies.
     */
    public function forEntry(ConsoleEntry $entry): ?self
    {
        foreach ($entry->getNames() as $name) {
            if (array_key_exists($name, $this->servicesByEntry)) {
                return $this->withService($this->servicesByEntry[$name]);
            }
        }

        if ($this->service === null) {
            return null;
        }

        return $this;
    }

    /**
     * Creates a copy using a specific Docker Compose service.
     *
     * @param  string  $service
     *   The Docker Compose service to use.
     *
     * @return self
     *   Returns a copy with __service__ set as effective service.
     */
    private function withService(string $service): self
    {
        return new self($this->enabled, $service, $this->servicesByEntry, $this->mode, $this->composeFiles, $this->projectDirectory, $this->workdir, $this->exclude);
    }

    /**
     * Normalizes a decoded Laravel config object.
     *
     * @param  array<mixed>  $raw
     *   The raw config value.
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
                throw new InvalidArgumentException('docker_composer must be an array with string keys.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Reads the enabled flag.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration array.
     *
     * @return bool
     *   Returns `false` when omitted, otherwise the configured boolean.
     *
     * @throws InvalidArgumentException
     *   Thrown when `enabled` is not boolean-like.
     */
    private static function enabled(array $raw): bool
    {
        if (! array_key_exists('enabled', $raw)) {
            return false;
        }

        if (is_bool($raw['enabled'])) {
            return $raw['enabled'];
        }

        if (is_int($raw['enabled'])) {
            if (! in_array($raw['enabled'], [0, 1], true)) {
                throw new InvalidArgumentException('docker_composer.enabled must be a boolean.');
            }

            return $raw['enabled'] === 1;
        }

        if (is_string($raw['enabled'])) {
            $value = filter_var($raw['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                return $value;
            }
        }

        throw new InvalidArgumentException('docker_composer.enabled must be a boolean.');
    }

    /**
     * Reads an optional non-empty `string` value.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration array.
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
            throw new InvalidArgumentException(sprintf('docker_composer.%s must be a non-empty string.', $key));
        }

        return $raw[$key];
    }

    /**
     * Reads the configured Docker Compose mode.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration array.
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
            return DockerComposeOptions::MODE_EXEC;
        }

        if (! is_string($raw['mode']) || ! in_array($raw['mode'], [DockerComposeOptions::MODE_EXEC, DockerComposeOptions::MODE_RUN], true)) {
            throw new InvalidArgumentException('docker_composer.mode must be "exec" or "run".');
        }

        return $raw['mode'];
    }

    /**
     * Reads Docker Compose file settings.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration array.
     *
     * @return list<string>
     *   Returns file paths configured by `compose_files`.
     *
     * @throws InvalidArgumentException
     *   Thrown when `compose_files` is not a non-empty `string` or list.
     */
    private static function composeFiles(array $raw): array
    {
        if (! array_key_exists('compose_files', $raw) || $raw['compose_files'] === null) {
            return [];
        }

        if (is_string($raw['compose_files'])) {
            if ($raw['compose_files'] === '') {
                throw new InvalidArgumentException('docker_composer.compose_files must contain non-empty strings.');
            }

            return [$raw['compose_files']];
        }

        return self::stringList($raw, 'compose_files');
    }

    /**
     * Reads a list of non-empty `string` values.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration array.
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
            throw new InvalidArgumentException(sprintf('docker_composer.%s must be a list of strings.', $key));
        }

        $values = [];
        foreach ($raw[$key] as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('docker_composer.%s must contain only non-empty strings.', $key));
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Reads service mapping settings as Laravel entry service overrides.
     *
     * @param  array<string, mixed>  $raw
     *   The normalized configuration array.
     *
     * @return array<string, string>
     *   Returns Docker Compose services keyed by Laravel entry identifier.
     *
     * @throws InvalidArgumentException
     *   Thrown when `service_mapping` has an invalid shape.
     */
    private static function serviceMapping(array $raw): array
    {
        $key = 'service_mapping';
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return [];
        }

        if ($raw[$key] === []) {
            return [];
        }

        if (! is_array($raw[$key]) || array_is_list($raw[$key])) {
            throw new InvalidArgumentException(sprintf('docker_composer.%s must be an object of strings or lists of strings.', $key));
        }

        $values = [];
        foreach ($raw[$key] as $service => $entries) {
            if (! is_string($service) || $service === '') {
                throw new InvalidArgumentException(sprintf('docker_composer.%s must use non-empty string keys.', $key));
            }

            if (is_string($entries)) {
                $entries = [$entries];
            }

            if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
                throw new InvalidArgumentException(sprintf('docker_composer.%s must contain only non-empty strings or lists of non-empty strings.', $key));
            }

            foreach ($entries as $entry) {
                if (! is_string($entry) || $entry === '') {
                    throw new InvalidArgumentException(sprintf('docker_composer.%s must contain only non-empty strings or lists of non-empty strings.', $key));
                }

                self::addServiceMapping($values, $entry, $service, $key);
                foreach (self::commandNamesForClass($entry) as $commandName) {
                    self::addServiceMapping($values, $commandName, $service, $key);
                }
            }
        }

        return $values;
    }

    /**
     * Adds a service mapping entry.
     *
     * @param  array<string, string>  $values
     *   The service mappings accumulated so far.
     *
     * @param  string  $entry
     *   The entry identifier to map.
     *
     * @param  string  $service
     *   The Docker Compose service to use.
     *
     * @param  string  $key
     *   The source config key used in validation messages.
     *
     * @return void
     *   Returns nothing.
     *
     * @throws InvalidArgumentException
     *   Thrown when __entry__ already maps to a different service.
     */
    private static function addServiceMapping(array &$values, string $entry, string $service, string $key): void
    {
        if (array_key_exists($entry, $values) && $values[$entry] !== $service) {
            throw new InvalidArgumentException(sprintf('docker_composer.%s must not assign an entry to multiple services.', $key));
        }

        $values[$entry] = $service;
    }

    /**
     * Reads command names declared by a Laravel command class.
     *
     * @param  string  $class
     *   The possible command class name.
     *
     * @return list<string>
     *   Returns command names declared through `$signature` or `$name`.
     */
    private static function commandNamesForClass(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $defaults = (new \ReflectionClass($class))->getDefaultProperties();
        $names = [];
        $signature = $defaults['signature'] ?? null;
        if (is_string($signature) && trim($signature) !== '') {
            $names[] = strtok(trim($signature), " \t\r\n") ?: '';
        }

        $name = $defaults['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $names[] = $name;
        }

        return array_values(array_filter(array_unique($names), static fn(string $name): bool => $name !== ''));
    }

    /**
     * Adds command names declared by class-string entries.
     *
     * @param  list<string>  $entries
     *   The configured entry identifiers.
     *
     * @return list<string>
     *   Returns entries plus command names derived from command classes.
     */
    private static function expandClassEntries(array $entries): array
    {
        $expanded = $entries;
        foreach ($entries as $entry) {
            foreach (self::commandNamesForClass($entry) as $commandName) {
                $expanded[] = $commandName;
            }
        }

        return array_values(array_unique($expanded));
    }
}
