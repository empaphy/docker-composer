<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use empaphy\docker_composer\DockerComposeOptions;
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
    public function testUsesDefaultsForOmittedConfig(): void
    {
        $config = Config::fromArray([]);

        self::assertFalse($config->isEnabled());
        self::assertSame(DockerComposeOptions::MODE_EXEC, $config->getMode());
        self::assertSame([], $config->getComposeFiles());
        self::assertNull($config->getProjectDirectory());
        self::assertNull($config->getWorkdir());
        self::assertFalse($config->excludes(ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate'])));
        self::assertNull($config->forEntry(ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate'])));
    }

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

    public function testAcceptsEnabledForms(): void
    {
        /** @var list<array{0: mixed, 1: bool}> $cases */
        $cases = [
            [true, true],
            [false, false],
            [1, true],
            [0, false],
            ['true', true],
            ['false', false],
            ['yes', true],
            ['no', false],
            ['on', true],
            ['off', false],
            ['1', true],
            ['0', false],
        ];

        foreach ($cases as [$value, $expected]) {
            self::assertSame($expected, Config::fromArray(['enabled' => $value])->isEnabled());
        }
    }

    public function testRejectsInvalidEnabledValues(): void
    {
        $this->assertInvalidConfig(['enabled' => 2], 'docker_composer.enabled must be a boolean.');
        $this->assertInvalidConfig(['enabled' => 'maybe'], 'docker_composer.enabled must be a boolean.');
        $this->assertInvalidConfig(['enabled' => []], 'docker_composer.enabled must be a boolean.');
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

    public function testRejectsNonStringTopLevelKeys(): void
    {
        $this->assertInvalidConfig([0 => 'invalid'], 'docker_composer must be an array with string keys.');
    }

    public function testRejectsInvalidOptionalStrings(): void
    {
        foreach (['service', 'project_directory', 'workdir'] as $key) {
            $this->assertInvalidConfig([$key => ''], sprintf('docker_composer.%s must be a non-empty string.', $key));
            $this->assertInvalidConfig([$key => false], sprintf('docker_composer.%s must be a non-empty string.', $key));
        }
    }

    public function testRejectsInvalidModeAndLists(): void
    {
        $this->assertInvalidConfig(['mode' => 'invalid'], 'docker_composer.mode must be "exec" or "run".');
        $this->assertInvalidConfig(['mode' => false], 'docker_composer.mode must be "exec" or "run".');
        $this->assertInvalidConfig(['compose_files' => ''], 'docker_composer.compose_files must contain non-empty strings.');
        $this->assertInvalidConfig(['compose_files' => false], 'docker_composer.compose_files must be a list of strings.');
        $this->assertInvalidConfig(['compose_files' => ['path' => 'docker-compose.yaml']], 'docker_composer.compose_files must be a list of strings.');
        $this->assertInvalidConfig(['compose_files' => ['']], 'docker_composer.compose_files must contain only non-empty strings.');
        $this->assertInvalidConfig(['compose_files' => [false]], 'docker_composer.compose_files must contain only non-empty strings.');
        $this->assertInvalidConfig(['exclude' => false], 'docker_composer.exclude must be a list of strings.');
        $this->assertInvalidConfig(['exclude' => ['command' => 'migrate']], 'docker_composer.exclude must be a list of strings.');
        $this->assertInvalidConfig(['exclude' => ['']], 'docker_composer.exclude must contain only non-empty strings.');
        $this->assertInvalidConfig(['exclude' => [false]], 'docker_composer.exclude must contain only non-empty strings.');
    }

    public function testAcceptsComposeFileListAndEmptyServiceMapping(): void
    {
        $config = Config::fromArray([
            'compose_files' => ['docker-compose.yaml', 'docker-compose.override.yaml'],
            'service_mapping' => [],
        ]);

        self::assertSame(['docker-compose.yaml', 'docker-compose.override.yaml'], $config->getComposeFiles());
        self::assertNull($config->forEntry(ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate'])));
    }

    public function testRejectsInvalidMappingShapes(): void
    {
        $this->assertInvalidConfig(['service_mapping' => 'php'], 'docker_composer.service_mapping must be an object of strings or lists of strings.');
        $this->assertInvalidConfig(['service_mapping' => ['php']], 'docker_composer.service_mapping must be an object of strings or lists of strings.');
        $this->assertInvalidConfig(['service_mapping' => ['' => 'migrate']], 'docker_composer.service_mapping must use non-empty string keys.');
        $this->assertInvalidConfig(['service_mapping' => [1 => 'migrate']], 'docker_composer.service_mapping must use non-empty string keys.');
        $this->assertInvalidConfig(['service_mapping' => ['php' => []]], 'docker_composer.service_mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['service_mapping' => ['php' => ['migrate' => 'migrate']]], 'docker_composer.service_mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['service_mapping' => ['php' => false]], 'docker_composer.service_mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['service_mapping' => ['php' => '']], 'docker_composer.service_mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['service_mapping' => ['php' => ['']]], 'docker_composer.service_mapping must contain only non-empty strings or lists of non-empty strings.');
    }

    public function testExpandsNamePropertyAndAcceptsDuplicateSameServiceMapping(): void
    {
        $config = Config::fromArray([
            'service_mapping' => [
                'php-tools' => [
                    NamedCommand::class,
                    'migrate',
                    'migrate',
                ],
            ],
        ]);

        self::assertSame('php-tools', $config->forEntry(ConsoleEntry::artisan('named:run', null, ['artisan', 'named:run']))?->getService());
        self::assertSame('php-tools', $config->forEntry(ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate']))?->getService());
    }

    public function testRejectsDuplicateMappingToDifferentServices(): void
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

    /**
     * @param  array<mixed>  $raw
     */
    private function assertInvalidConfig(array $raw, string $message): void
    {
        try {
            Config::fromArray($raw);
            self::fail(sprintf('Expected invalid config exception for message "%s".', $message));
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
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

final class NamedCommand
{
    protected string $name = 'named:run';
}
