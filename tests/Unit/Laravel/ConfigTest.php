<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use empaphy\docker_composer\Laravel\Config;
use empaphy\docker_composer\Laravel\ConsoleEntry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(Config::class)]
#[CoversClass(ConsoleEntry::class)]
final class ConfigTest extends TestCase
{
    public function testParsesLaravelConfig(): void
    {
        $config = Config::fromArray([
            'enabled' => 'true',
            'service' => 'php',
            'mode' => 'run',
            'compose_files' => 'docker-compose.yaml',
            'project_directory' => '.',
            'workdir' => '/usr/src/app',
            'exclude' => ['queue:work', ExcludedSignatureCommand::class],
            'service_mapping' => [
                'php-tools' => [
                    'config:cache',
                    ExampleCommand::class,
                    SignatureCommand::class,
                    ':scripts/task.php',
                ],
            ],
        ]);

        self::assertTrue($config->isEnabled());
        self::assertSame('php', $config->getService());
        self::assertSame('run', $config->getMode());
        self::assertSame(['docker-compose.yaml'], $config->getComposeFiles());
        self::assertSame('.', $config->getProjectDirectory());
        self::assertSame('/usr/src/app', $config->getWorkdir());
        self::assertTrue($config->excludes(ConsoleEntry::artisan('queue:work', null, ['artisan', 'queue:work'])));
        self::assertTrue($config->excludes(ConsoleEntry::artisan('excluded:run', null, ['artisan', 'excluded:run'])));
        self::assertSame('php-tools', $config->forEntry(ConsoleEntry::artisan('config:cache', null, ['artisan', 'config:cache']))?->getService());
        self::assertSame('php-tools', $config->forEntry(ConsoleEntry::artisan(null, ExampleCommand::class, ['artisan', 'example']))?->getService());
        self::assertSame('php-tools', $config->forEntry(ConsoleEntry::artisan('signature:run', null, ['artisan', 'signature:run']))?->getService());
        self::assertSame('php-tools', $config->forEntry(ConsoleEntry::script(':scripts/task.php', ['scripts/task.php']))?->getService());
    }

    public function testDefaultServiceAppliesWhenNoMappingMatches(): void
    {
        $config = Config::fromArray([
            'enabled' => true,
            'service' => 'php',
        ]);

        self::assertSame('php', $config->forEntry(ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate']))?->getService());
    }

    public function testMissingServiceLeavesEntryUnconfigured(): void
    {
        $config = Config::fromArray([
            'enabled' => true,
        ]);

        self::assertNull($config->forEntry(ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate'])));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Docker Compose service is not configured.');

        $config->getService();
    }

    public function testRejectsUnknownKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('docker_composer contains unknown key "unknown".');

        Config::fromArray(['unknown' => true]);
    }

    public function testRejectsInvalidMappingShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('docker_composer.service_mapping must not assign an entry to multiple services.');

        Config::fromArray([
            'service_mapping' => [
                'php' => 'migrate',
                'worker' => 'migrate',
            ],
        ]);
    }
}

final class ExampleCommand {}

final class SignatureCommand
{
    protected string $signature = 'signature:run {argument?}';
}

final class ExcludedSignatureCommand
{
    protected string $signature = 'excluded:run';
}
