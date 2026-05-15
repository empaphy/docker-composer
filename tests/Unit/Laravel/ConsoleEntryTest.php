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

    public function testCreatesRelativeScriptName(): void
    {
        self::assertSame(':', ConsoleEntry::scriptName('/host/app', '/host/app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('/host/app/scripts/task.php', '/host/app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('scripts/task.php', '/host/app'));

        $entry = ConsoleEntry::script(':scripts/task.php', ['scripts/task.php']);

        self::assertSame(':scripts/task.php', $entry->getDisplayName());
    }
}

final class ExampleConsoleEntryCommand {}
