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
     * Opens process resources.
     *
     * @var callable(list<string>, array<int, mixed>, array<int, resource>): (resource|false)
     *   Returns an open process resource, or `false` when startup fails.
     */
    private $processOpener;

    /**
     * Stores stderr captured from the last command.
     */
    private string $errorOutput = '';

    /**
     * Creates a shell-backed process runner.
     *
     * @param  (callable(list<string>, array<int, mixed>, array<int, resource>): (resource|false))|null  $processOpener
     *   The process opener, or `null` to use `proc_open`.
     */
    public function __construct(?callable $processOpener = null)
    {
        $this->processOpener = $processOpener ?? 'proc_open';
    }

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
            0 => ['file', 'php://stdin', 'r'],
            1 => $captureOutput ? ['pipe', 'w'] : ['file', 'php://stdout', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = ($this->processOpener)($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            $this->errorOutput = 'Unable to start process.';

            return 1;
        }

        if ($captureOutput) {
            $output = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
        }

        $this->errorOutput = stream_get_contents($pipes[2]) ?: '';
        if ($this->errorOutput !== '') {
            file_put_contents('php://stderr', $this->errorOutput);
        }

        fclose($pipes[2]);

        return proc_close($process);
    }
}
