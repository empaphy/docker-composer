<?php

/**
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
     */
    private $ttyDetector;

    /**
     * Creates a Composer-backed process runner.
     *
     * @param null|callable(): bool $ttyDetector
     */
    public function __construct(IOInterface $io, ?callable $ttyDetector = null)
    {
        $this->processExecutor = new ProcessExecutor($io);
        $this->ttyDetector = $ttyDetector ?? [self::class, 'detectTtySupport'];
    }

    /**
     * Runs a command with optional TTY passthrough.
     *
     * @param list<string> $command
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
     */
    public function getErrorOutput(): string
    {
        return $this->processExecutor->getErrorOutput();
    }

    /**
     * Checks whether TTY execution is available.
     */
    public function supportsTty(): bool
    {
        return (new \ReflectionObject($this->processExecutor))->hasMethod('executeTty')
            && ($this->ttyDetector)();
    }

    /**
     * Escapes command arguments for Composer's shell executor.
     *
     * @param list<string> $command
     */
    private function escapeCommand(array $command): string
    {
        return implode(' ', array_map([ProcessExecutor::class, 'escape'], $command));
    }

    /**
     * Detects TTY support in the current Composer process.
     */
    private static function detectTtySupport(): bool
    {
        if (class_exists(Platform::class) && is_callable([Platform::class, 'isTty'])) {
            return Platform::isTty();
        }

        return defined('STDOUT') && stream_isatty(STDOUT);
    }
}
