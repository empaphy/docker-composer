<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use empaphy\docker_composer\ComposerProcessRunner;
use empaphy\docker_composer\ContainerDetector;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposerConfig;
use empaphy\docker_composer\DockerComposerPlugin;
use empaphy\docker_composer\EnvironmentContainerDetector;
use empaphy\docker_composer\OutputCapturingProcessRunner;
use empaphy\docker_composer\ProcessRunner;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\TestCase;

#[CoversClass(DockerComposerConfig::class)]
class DockerComposerConfigTest extends TestCase
{
    public function testConfigDefaultsWhenDockerComposerExtraIsMissing(): void
    {
        [$composer] = $this->createComposer([], []);

        $config = DockerComposerConfig::fromComposer($composer);

        self::assertFalse($config->isConfigured());
        self::assertSame(DockerComposerConfig::MODE_EXEC, $config->getMode());
        self::assertSame([], $config->getComposeFiles());
        self::assertNull($config->getProjectDirectory());
        self::assertNull($config->getWorkdir());
        self::assertFalse($config->isExcluded('test'));
        self::assertSame([], $config->getUnknownKeys());
    }

    public function testConfigAcceptsEmptyServiceMapping(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => [
                'service-mapping' => [],
            ],
        ]);

        $config = DockerComposerConfig::fromComposer($composer);

        self::assertFalse($config->isConfiguredForScript('test'));
    }

    public function testConfigRejectsInvalidShapes(): void
    {
        $this->assertInvalidConfig(['docker-composer' => 'invalid'], 'extra.docker-composer must be an object.');
        $this->assertInvalidConfig(['docker-composer' => [0 => 'invalid']], 'extra.docker-composer must be an object.');
        $this->assertInvalidConfig(['docker-composer' => ['service' => '']], 'extra.docker-composer.service must be a non-empty string.');
        $this->assertInvalidConfig(['docker-composer' => ['compose-files' => '']], 'extra.docker-composer.compose-files must contain non-empty strings.');
        $this->assertInvalidConfig(['docker-composer' => ['exclude' => ['script' => true]]], 'extra.docker-composer.exclude must be a list of strings.');
        $this->assertInvalidConfig(['docker-composer' => ['exclude' => [1]]], 'extra.docker-composer.exclude must contain only non-empty strings.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => 'php']], 'extra.docker-composer.service-mapping must be an object of strings or lists of strings.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['php']]], 'extra.docker-composer.service-mapping must be an object of strings or lists of strings.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['' => 'test']]], 'extra.docker-composer.service-mapping must use non-empty string keys.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['php' => '']]], 'extra.docker-composer.service-mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['php' => ['test' => 'test']]]], 'extra.docker-composer.service-mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['php' => ['']]]], 'extra.docker-composer.service-mapping must contain only non-empty strings or lists of non-empty strings.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['php' => []]]], 'extra.docker-composer.service-mapping must map each service to at least one script.');
        $this->assertInvalidConfig(['docker-composer' => ['service-mapping' => ['php' => 'test', 'php-tools' => ['test']]]], 'extra.docker-composer.service-mapping must not assign a script to multiple services.');
    }

    public function testUnconfiguredServiceAccessFails(): void
    {
        [$composer] = $this->createComposer([], []);
        $config = DockerComposerConfig::fromComposer($composer);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Docker Compose service is not configured.');

        $config->getService();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function assertInvalidConfig(array $extra, string $message): void
    {
        [$composer] = $this->createComposer([], $extra);

        try {
            DockerComposerConfig::fromComposer($composer);
            self::fail(sprintf('Expected invalid config exception for message "%s".', $message));
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
