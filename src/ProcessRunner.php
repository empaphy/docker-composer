<?php

/**
 * Defines the process runner contract.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Runs external commands for Docker Composer.
 */
interface ProcessRunner
{
    /**
     * Runs a command and returns its process status.
     *
     * @param  list<string>  $command
     *   The command arguments to execute.
     *
     * @param  bool  $tty
     *   Whether to request TTY passthrough.
     *
     * @return int
     *   Returns the command exit code.
     */
    public function run(array $command, bool $tty = false): int;

    /**
     * Gets stderr captured from the last command.
     *
     * @return string
     *   Returns the last process error output.
     */
    public function getErrorOutput(): string;

    /**
     * Checks whether TTY passthrough is available.
     *
     * @return bool
     *   Returns `true` when interactive execution can use a TTY.
     */
    public function supportsTty(): bool;
}
