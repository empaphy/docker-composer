<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use empaphy\docker_composer\OutputCapturingProcessRunner;

use function array_shift;

final class MockOutputCapturingProcessRunner extends MockProcessRunner implements OutputCapturingProcessRunner
{
    /** @var list<string> */
    private array $outputs;

    /**
     * @param list<int>    $exitCodes
     * @param list<string> $outputs
     */
    public function __construct(
        array $exitCodes = [0],
        string $errorOutput = '',
        bool $supportsTty = false,
        array $outputs = [],
    ) {
        parent::__construct($exitCodes, $errorOutput, $supportsTty);
        $this->outputs = $outputs;
    }

    public function runWithOutput(array $command, string &$output): int
    {
        $output = array_shift($this->outputs) ?? '';

        return $this->run($command);
    }
}
