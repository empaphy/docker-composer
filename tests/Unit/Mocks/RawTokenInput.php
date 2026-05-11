<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Symfony\Component\Console\Input\ArrayInput;

/**
 * Provides Symfony Console raw token access.
 */
final class RawTokenInput extends ArrayInput
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
     * Creates a raw token input.
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
     *   Returns the configured first argument.
     */
    public function getFirstArgument(): ?string
    {
        return $this->firstArgument;
    }

    /**
     * Returns unparsed raw tokens.
     *
     * @param  bool  $strip
     *   Whether to return only tokens after the command-like first argument.
     *
     * @return list<string>
     *   Returns raw input tokens.
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
