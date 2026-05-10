<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Symfony\Component\Console\Input\ArrayInput;

/**
 * Provides malformed Symfony Console 7.0-style raw token storage.
 */
final class InvalidLegacyTokenInput extends ArrayInput
{
    /**
     * Stores malformed raw input tokens.
     */
    private mixed $tokens;

    /**
     * Creates an invalid legacy token input.
     *
     * @param  mixed  $tokens
     *   The malformed raw token payload.
     */
    public function __construct(mixed $tokens)
    {
        $this->tokens = $tokens;

        parent::__construct(['install']);
    }

    /**
     * Returns the malformed raw token payload.
     *
     * @return mixed
     *   Returns the malformed raw token payload.
     */
    public function getTokensForAssertion(): mixed
    {
        return $this->tokens;
    }

    /**
     * Returns the first command-like argument.
     *
     * @return string
     *   Returns `install`.
     */
    public function getFirstArgument(): string
    {
        return 'install';
    }
}
