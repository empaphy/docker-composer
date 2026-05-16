<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use empaphy\docker_composer\Laravel\ConsoleEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ConsoleEntry::class)]
final class ConsoleEntryTest extends TestCase
{
    public function testCreatesArtisanEntryNames(): void
    {
        $entry = ConsoleEntry::artisan('config:cache', ExampleConsoleEntryCommand::class, ['artisan', 'config:cache']);

        self::assertSame(['config:cache', ExampleConsoleEntryCommand::class], $entry->getNames());
        self::assertSame(['artisan', 'config:cache'], $entry->getArguments());
        self::assertSame('artisan config:cache', $entry->getDisplayName());
    }

    public function testCreatesFallbackArtisanDisplayName(): void
    {
        $entry = ConsoleEntry::artisan(null, null, ['/host/app/artisan']);

        self::assertSame([], $entry->getNames());
        self::assertSame('/host/app/artisan', $entry->getDisplayName());
    }

    public function testIgnoresEmptyArtisanNames(): void
    {
        $method = new \ReflectionMethod(ConsoleEntry::class, 'artisan');
        $entry = $method->invoke(null, '', '', ['artisan']);

        self::assertInstanceOf(ConsoleEntry::class, $entry);
        self::assertSame([], $entry->getNames());
        self::assertSame('artisan', $entry->getDisplayName());
    }

    public function testDeduplicatesArtisanNames(): void
    {
        $entry = ConsoleEntry::artisan(DuplicateConsoleEntryCommand::class, DuplicateConsoleEntryCommand::class, ['artisan']);

        self::assertSame([DuplicateConsoleEntryCommand::class], $entry->getNames());
    }

    public function testCreatesRelativeScriptName(): void
    {
        self::assertSame(':', ConsoleEntry::scriptName('/host/app', '/host/app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('/host/app/scripts/task.php', '/host/app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('scripts/task.php', '/host/app'));

        $entry = ConsoleEntry::script(':scripts/task.php', ['scripts/task.php']);

        self::assertSame(':scripts/task.php', $entry->getDisplayName());
    }

    public function testNormalizesWindowsScriptNames(): void
    {
        self::assertSame(':', ConsoleEntry::scriptName('C:\\host\\app\\', 'C:\\host\\app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('C:\\host\\app\\scripts\\task.php', 'C:\\host\\app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('scripts\\task.php', 'C:\\host\\app'));
    }
}

final class ExampleConsoleEntryCommand {}

final class DuplicateConsoleEntryCommand {}
