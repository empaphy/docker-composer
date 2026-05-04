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

    public function __construct(IOInterface $io)
    {
        $this->processExecutor = new ProcessExecutor($io);
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
        return (new \ReflectionObject($this->processExecutor))->hasMethod('executeTty');
    }

    /**
     * @param list<string> $command
     */
    private function escapeCommand(array $command): string
    {
        return implode(' ', array_map([ProcessExecutor::class, 'escape'], $command));
    }
}
