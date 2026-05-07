<?php

/**
 * Defines Composer-backed process execution.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\IO\IOInterface;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

/**
 * Runs commands through Composer's process executor.
 */
final class ComposerProcessRunner implements ProcessRunner
{
    /**
     * Executes escaped shell commands.
     */
    private ProcessExecutor $processExecutor;

    /**
     * Detects TTY support for interactive commands.
     *
     * @var callable(): bool
     *   Returns `true` when TTY passthrough is available.
     */
    private $ttyDetector;

    /**
     * Creates a Composer-backed process runner.
     *
     * @param  IOInterface  $io
     *   The Composer IO used by the process executor.
     *
     * @param  (callable(): bool)|null  $ttyDetector
     *   The TTY support detector, or `null` for platform detection.
     */
    public function __construct(IOInterface $io, ?callable $ttyDetector = null)
    {
        $this->processExecutor = new ProcessExecutor($io);
        $this->ttyDetector = $ttyDetector ?? [self::class, 'detectTtySupport'];
    }

    /**
     * Runs a command with optional TTY passthrough.
     *
     * @param  list<string>  $command
     *   The command arguments to escape and execute.
     *
     * @param  bool  $tty
     *   Whether to request TTY passthrough when supported.
     *
     * @return int
     *   Returns the process exit code.
     */
    public function run(array $command, bool $tty = false): int
    {
        $escapedCommand = $this->escapeCommand($command);
        if ($tty && $this->supportsTty()) {
            return $this->processExecutor->executeTty($escapedCommand);
        }

        return $this->processExecutor->execute($escapedCommand);
    }

    /**
     * Gets the last process error output.
     *
     * @return string
     *   Returns stderr captured from the last executed command.
     */
    public function getErrorOutput(): string
    {
        return $this->processExecutor->getErrorOutput();
    }

    /**
     * Checks whether TTY execution is available.
     *
     * @return bool
     *   Returns `true` when Composer supports TTY execution and the terminal is a TTY.
     */
    public function supportsTty(): bool
    {
        return (new \ReflectionObject($this->processExecutor))->hasMethod('executeTty')
            && ($this->ttyDetector)();
    }

    /**
     * Escapes command arguments for Composer's shell executor.
     *
     * @param  list<string>  $command
     *   The raw command arguments to escape.
     *
     * @return string
     *   Returns a shell-safe command string.
     */
    private function escapeCommand(array $command): string
    {
        return implode(' ', array_map([ProcessExecutor::class, 'escape'], $command));
    }

    /**
     * Detects TTY support in the current Composer process.
     *
     * @return bool
     *   Returns `true` when the current process output is attached to a TTY.
     */
    private static function detectTtySupport(): bool
    {
        if (class_exists(Platform::class) && is_callable([Platform::class, 'isTty'])) {
            return Platform::isTty();
        }

        return defined('STDOUT') && stream_isatty(STDOUT);
    }
}
