<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Symfony\Component\Console\Input\ArrayInput;

final class LegacyTokenInput extends ArrayInput
{
    /**
     * @var list<string>
     */
    private array $tokens;

    private ?string $firstArgument;

    /**
     * @param  list<string>  $tokens
     */
    public function __construct(array $tokens, ?string $firstArgument = null)
    {
        $this->tokens = $tokens;
        $this->firstArgument = $firstArgument;

        parent::__construct($tokens);
    }

    public function getFirstArgument(): ?string
    {
        if ($this->firstArgument !== null) {
            return $this->firstArgument;
        }

        foreach ($this->tokens as $token) {
            if ($token !== '' && $token[0] !== '-') {
                return $token;
            }
        }

        return null;
    }
}
