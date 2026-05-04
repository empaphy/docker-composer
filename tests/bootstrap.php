<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;

require __DIR__ . '/../vendor/autoload.php';

if (! class_exists(CoversTrait::class)) {
    class_alias(CoversClass::class, CoversTrait::class);
}
