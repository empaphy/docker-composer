<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Composer\Util\ProcessExecutor;

final class MockProcessExecutor extends ProcessExecutor
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    public array $ttyCommands = [];

    /**
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        private int $executeExitCode,
        private int $ttyExitCode,
        private string $testErrorOutput,
        private string $testOutput = '',
    ) {}

    /**
     * @param mixed $command
     * @param mixed $output
     */
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        $this->commands[] = (string) $command;
        $output = $this->testOutput;

        return $this->executeExitCode;
    }

    /**
     * @param mixed $command
     */
    public function executeTty($command, ?string $cwd = null): int
    {
        $this->ttyCommands[] = (string) $command;

        return $this->ttyExitCode;
    }

    public function getErrorOutput(): string
    {
        return $this->testErrorOutput;
    }
}
