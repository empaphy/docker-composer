<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Symfony\Component\Console\Input\ArrayInput;

/**
 * Provides Symfony Console 7.0-style raw token storage.
 */
final class LegacyTokenInput extends ArrayInput
{
    /**
     * Stores raw input tokens.
     *
     * @var list<string>
     */
    private array $tokens;

    /**
     * Stores the command-like first argument.
     */
    private ?string $firstArgument;

    /**
     * Creates a legacy token input.
     *
     * @param  list<string>  $tokens
     *   The raw input tokens without the Composer executable.
     *
     * @param  string|null  $firstArgument
     *   The command-like first argument to return.
     */
    public function __construct(array $tokens, ?string $firstArgument = null)
    {
        $this->tokens = $tokens;
        $this->firstArgument = $firstArgument;

        parent::__construct($tokens);
    }

    /**
     * Returns the first command-like argument.
     *
     * @return string|null
     *   Returns the first token that is not an option.
     */
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
