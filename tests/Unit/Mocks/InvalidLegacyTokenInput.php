<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Symfony\Component\Console\Input\ArrayInput;

final class InvalidLegacyTokenInput extends ArrayInput
{
    private mixed $tokens;

    public function __construct(mixed $tokens)
    {
        $this->tokens = $tokens;

        parent::__construct(['install']);
    }

    public function getTokensForAssertion(): mixed
    {
        return $this->tokens;
    }

    public function getFirstArgument(): string
    {
        return 'install';
    }
}
