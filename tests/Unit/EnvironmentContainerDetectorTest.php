<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use empaphy\docker_composer\EnvironmentContainerDetector;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EnvironmentContainerDetector::class)]
class EnvironmentContainerDetectorTest extends TestCase
{
    #[BackupGlobals(true)]
    public function testEnvironmentDetectorUsesExplicitMarker(): void
    {
        putenv('DOCKER_COMPOSER_INSIDE=1');
        try {
            self::assertTrue((new EnvironmentContainerDetector())->isInsideContainer());
        } finally {
            putenv('DOCKER_COMPOSER_INSIDE');
        }
    }

    public function testEnvironmentDetectorUsesContainerFilesAndCgroups(): void
    {
        $getEnv = static fn(string $name): bool => false;
        $missingFiles = static fn(string $path): bool => false;

        self::assertTrue((new EnvironmentContainerDetector(
            $getEnv,
            static fn(string $path): bool => $path === '/.dockerenv',
            static fn(string $path): bool => false,
        ))->isInsideContainer());
        self::assertFalse((new EnvironmentContainerDetector(
            $getEnv,
            $missingFiles,
            static fn(string $path): bool => false,
        ))->isInsideContainer());
        self::assertFalse((new EnvironmentContainerDetector(
            $getEnv,
            $missingFiles,
            static fn(string $path): string => '0::/user.slice',
        ))->isInsideContainer());
        self::assertTrue((new EnvironmentContainerDetector(
            $getEnv,
            $missingFiles,
            static fn(string $path): string => '0::/kubepods.slice/containerd',
        ))->isInsideContainer());
    }
}
