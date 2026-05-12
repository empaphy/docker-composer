<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Composer\Util\ProcessExecutor;

final class MockProcessExecutor extends ProcessExecutor
{
    /**
     * @var list<string|non-empty-list<string>>
     */
    public array $commands = [];

    /**
     * @var list<string|non-empty-list<string>>
     */
    public array $ttyCommands = [];

    /**
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        private readonly int $executeExitCode,
        private readonly int $ttyExitCode,
        private readonly string $testErrorOutput,
        private readonly string $testOutput = '',
    ) {}

    /**
     * @param  string|non-empty-list<string>  $command
     * @param  mixed                          $output
     */
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        $this->commands[] = $command;
        $output = $this->testOutput;

        return $this->executeExitCode;
    }

    /**
     * @param  string|non-empty-list<string>  $command
     */
    public function executeTty($command, ?string $cwd = null): int
    {
        $this->ttyCommands[] = $command;

        return $this->ttyExitCode;
    }

    public function getErrorOutput(): string
    {
        return $this->testErrorOutput;
    }
}
