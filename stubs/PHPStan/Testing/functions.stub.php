<?php

declare(strict_types = 1);

namespace PHPStan\Testing;

use PHPStan\TrinaryLogic;

/**
 * Asserts the static type of a value.
 *
 * @param  mixed  $value
 * @return void
 */
function assertType(string $type, mixed $value): void {}

/**
 * Asserts the static type of a value.
 *
 * The difference from assertType() is that it doesn't resolve
 * method/function parameter phpDocs.
 *
 * @param  string  $type
 * @param  mixed  $value
 * @return void
 */
function assertNativeType(string $type, mixed $value)
{
	return null;
}

/**
 * Asserts a super type of a value.
 *
 * @param  string  $syperType
 * @param  mixed  $value
 * @return void
 */
function assertSuperType(string $superType, mixed $value): void {}

/**
 * @param  TrinaryLogic  $certainty
 * @param  mixed  $variable
 * @return void
 */
function assertVariableCertainty(TrinaryLogic $certainty, mixed $variable): void {}
