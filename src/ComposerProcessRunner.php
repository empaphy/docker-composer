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
use Composer\Util\ProcessExecutor;

final class ComposerProcessRunner implements ProcessRunner
{
    private ProcessExecutor $processExecutor;

    /** @var callable(): bool */
    private $ttyDetector;

    public function __construct(IOInterface $io, ?callable $ttyDetector = null)
    {
        $this->processExecutor = new ProcessExecutor($io);
        $this->ttyDetector = $ttyDetector ?? [self::class, 'detectTtySupport'];
    }

    /**
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

    public function getErrorOutput(): string
    {
        return $this->processExecutor->getErrorOutput();
    }

    public function supportsTty(): bool
    {
        return (new \ReflectionObject($this->processExecutor))->hasMethod('executeTty')
            && ($this->ttyDetector)();
    }

    /**
     * @param list<string> $command
     */
    private function escapeCommand(array $command): string
    {
        return implode(' ', array_map([ProcessExecutor::class, 'escape'], $command));
    }

    private static function detectTtySupport(): bool
    {
        $platformClass = 'Composer\\Util\\Platform';
        if (class_exists($platformClass) && is_callable([$platformClass, 'isTty'])) {
            return $platformClass::isTty();
        }

        if (! defined('STDOUT')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return stream_isatty(STDOUT);
        }

        if (function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }

        return false;
    }
}
