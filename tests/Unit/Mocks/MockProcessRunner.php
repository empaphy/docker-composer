<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use empaphy\docker_composer\ProcessRunner;

use function array_shift;

class MockProcessRunner implements ProcessRunner
{
    /**
     * @var list<list<string>>
     */
    public array $commands = [];

    /**
     * @var list<bool>
     */
    public array $tty = [];

    /**
     * @var list<int>
     */
    private array $exitCodes;

    /**
     * @param list<int> $exitCodes
     */
    public function __construct(
        array $exitCodes = [0],
        private readonly string $errorOutput = '',
        private readonly bool $supportsTty = false,
    ) {
        $this->exitCodes = $exitCodes;
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, bool $tty = false): int
    {
        $this->commands[] = $command;
        $this->tty[] = $tty;

        return array_shift($this->exitCodes) ?? 0;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    public function supportsTty(): bool
    {
        return $this->supportsTty;
    }
}
