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
    }

    public function testCreatesRelativeScriptName(): void
    {
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('/host/app/scripts/task.php', '/host/app'));
        self::assertSame(':scripts/task.php', ConsoleEntry::scriptName('scripts/task.php', '/host/app'));
    }
}

final class ExampleConsoleEntryCommand {}
