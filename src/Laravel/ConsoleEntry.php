<?php

/**
 * Defines Laravel console entry matching.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer\Laravel;

/**
 * Carries Laravel console entry identifiers and replay arguments.
 */
final class ConsoleEntry
{
    /**
     * Stores entry identifiers used for service mapping and exclusion.
     *
     * @var list<string>
     */
    private array $names;

    /**
     * Stores raw CLI arguments to replay in Docker.
     *
     * @var list<string>
     */
    private array $arguments;

    /**
     * Creates a Laravel console entry.
     *
     * @param  list<string>  $names
     *   The entry identifiers that can match configuration entries.
     *
     * @param  list<string>  $arguments
     *   The raw CLI arguments to replay inside Docker.
     */
    private function __construct(array $names, array $arguments)
    {
        $this->names = array_values(array_unique($names));
        $this->arguments = $arguments;
    }

    /**
     * Creates context for an Artisan command.
     *
     * @param  string|null  $commandName
     *   The Artisan command name, or `null` when unavailable.
     *
     * @param  class-string|null  $commandClass
     *   The Artisan command class, or `null` when unavailable.
     *
     * @param  list<string>  $arguments
     *   The raw CLI arguments to replay inside Docker.
     *
     * @return self
     *   Returns context for Artisan command matching.
     */
    public static function artisan(?string $commandName, ?string $commandClass, array $arguments): self
    {
        $names = [];
        if ($commandName !== null && $commandName !== '') {
            $names[] = $commandName;
        }

        if ($commandClass !== null && $commandClass !== '') {
            $names[] = $commandClass;
        }

        return new self($names, $arguments);
    }

    /**
     * Creates context for a custom Laravel bootstrap script.
     *
     * @param  string  $scriptName
     *   The script identifier, such as `":scripts/task.php"`.
     *
     * @param  list<string>  $arguments
     *   The raw CLI arguments to replay inside Docker.
     *
     * @return self
     *   Returns context for script matching.
     */
    public static function script(string $scriptName, array $arguments): self
    {
        return new self([$scriptName], $arguments);
    }

    /**
     * Creates a script identifier for a CLI entrypoint.
     *
     * @param  string  $entrypoint
     *   The first CLI argument.
     *
     * @param  string  $projectRoot
     *   The absolute Laravel project root.
     *
     * @return string
     *   Returns the script identifier prefixed with `:`.
     */
    public static function scriptName(string $entrypoint, string $projectRoot): string
    {
        $entrypoint = str_replace('\\', '/', $entrypoint);
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($entrypoint === $projectRoot) {
            return ':';
        }

        if (str_starts_with($entrypoint, $projectRoot . '/')) {
            return ':' . ltrim(substr($entrypoint, strlen($projectRoot)), '/');
        }

        return ':' . ltrim($entrypoint, '/');
    }

    /**
     * Gets entry identifiers used for matching.
     *
     * @return list<string>
     *   Returns command names, command classes, or script identifiers.
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * Gets raw CLI arguments.
     *
     * @return list<string>
     *   Returns arguments to replay inside Docker.
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }
}
