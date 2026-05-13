<?php

/**
 * Defines shell-backed process execution.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

/**
 * Runs commands through PHP process primitives without Composer IO.
 */
final class ShellProcessRunner implements OutputCapturingProcessRunner
{
    /**
     * Stores stderr captured from the last command.
     */
    private string $errorOutput = '';

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
    public function run(array $command, bool $tty = false): int
    {
        $output = '';

        return $this->runProcess($command, false, $output);
    }

    /**
     * Runs a command while capturing standard output.
     *
     * @param  list<string>  $command
     *   The command arguments to execute.
     *
     * @param  string  $output
     *   The captured standard output.
     *
     * @return int
     *   Returns the process exit code.
     */
    public function runWithOutput(array $command, string &$output): int
    {
        return $this->runProcess($command, true, $output);
    }

    /**
     * Gets stderr captured from the last command.
     *
     * @return string
     *   Returns the last process error output.
     */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * Checks whether TTY passthrough is available.
     *
     * @return bool
     *   Returns `false`; shell execution uses inherited streams.
     */
    public function supportsTty(): bool
    {
        return false;
    }

    /**
     * Runs a command with optional stdout capture.
     *
     * @param  list<string>  $command
     *   The command arguments to execute.
     *
     * @param  bool  $captureOutput
     *   Whether standard output should be captured instead of inherited.
     *
     * @param  string  $output
     *   The captured standard output.
     *
     * @return int
     *   Returns the process exit code.
     */
    private function runProcess(array $command, bool $captureOutput, string &$output): int
    {
        $this->errorOutput = '';
        $output = '';
        $descriptors = [
            0 => defined('STDIN') ? STDIN : ['file', 'php://stdin', 'r'],
            1 => $captureOutput ? ['pipe', 'w'] : (defined('STDOUT') ? STDOUT : ['file', 'php://stdout', 'w']),
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            $this->errorOutput = 'Unable to start process.';

            return 1;
        }

        if ($captureOutput && isset($pipes[1]) && is_resource($pipes[1])) {
            $output = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
        }

        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $this->errorOutput = stream_get_contents($pipes[2]) ?: '';
            if ($this->errorOutput !== '' && defined('STDERR')) {
                fwrite(STDERR, $this->errorOutput);
            }

            fclose($pipes[2]);
        }

        return proc_close($process);
    }
}
