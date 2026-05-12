<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Symfony\Component\Console\Input\ArrayInput;

final class RawTokenInput extends ArrayInput
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
        return $this->firstArgument;
    }

    /**
     * @return list<string>
     */
    public function getRawTokens(bool $strip = false): array
    {
        if (! $strip) {
            return $this->tokens;
        }

        $tokens = [];
        $keep = false;
        foreach ($this->tokens as $token) {
            if (! $keep && $token === $this->firstArgument) {
                $keep = true;

                continue;
            }

            if ($keep) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
