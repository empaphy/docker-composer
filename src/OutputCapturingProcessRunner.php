<?php

/**
 * Defines captured-output process execution.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Runs external commands and captures standard output.
 */
interface OutputCapturingProcessRunner extends ProcessRunner
{
    /**
     * Runs a command and captures its standard output.
     *
     * @param  list<string>  $command
     *   The command arguments to execute.
     *
     * @param  string  $output
     *   The captured standard output.
     *
     * @return int
     *   Returns the command exit code.
     */
    public function runWithOutput(array $command, string &$output): int;
}
