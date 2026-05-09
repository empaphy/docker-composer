<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use empaphy\docker_composer\ContainerDetector;

final class MockContainerDetector implements ContainerDetector
{
    public function __construct(private bool $inside) {}

    public function isInsideContainer(): bool
    {
        return $this->inside;
    }
}
