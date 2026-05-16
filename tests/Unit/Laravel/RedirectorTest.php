<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeExecutionResult;
use empaphy\docker_composer\DockerComposeResolvedOptions;
use empaphy\docker_composer\DockerComposeRunner;
use empaphy\docker_composer\DockerComposeWorkdirResolution;
use empaphy\docker_composer\DockerComposeWorkdirResolver;
use empaphy\docker_composer\EnvironmentContainerDetector;
use empaphy\docker_composer\Laravel\Config;
use empaphy\docker_composer\Laravel\ConsoleEntry;
use empaphy\docker_composer\Laravel\Redirector;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Tests\Unit\Mocks\MockContainerDetector;
use Tests\Unit\Mocks\MockOutputCapturingProcessRunner;
use Tests\Unit\Mocks\MockProcessRunner;

#[CoversClass(Redirector::class)]
#[CoversClass(Config::class)]
#[CoversClass(ConsoleEntry::class)]
#[CoversClass(DockerComposeRunner::class)]
#[CoversClass(DockerComposeCommandBuilder::class)]
#[CoversClass(DockerComposeExecutionResult::class)]
#[CoversClass(EnvironmentContainerDetector::class)]
#[CoversClass(DockerComposeWorkdirResolver::class)]
#[CoversClass(DockerComposeWorkdirResolution::class)]
#[CoversClass(DockerComposeResolvedOptions::class)]
final class RedirectorTest extends TestCase
{
    public function testRedirectsMatchingLaravelCommandThroughSharedDockerRunner(): void
    {
        $config = Config::fromArray([
            'enabled' => true,
            'service' => 'php',
            'workdir' => '/usr/src/app',
            'service_mapping' => [
                'php-tools' => 'config:cache',
            ],
        ]);
        $runner = new MockProcessRunner();
        $builder = new DockerComposeCommandBuilder();
        $errorOutput = $this->createErrorOutput();
        $redirector = new Redirector(new DockerComposeRunner($runner, $builder), $builder, new MockContainerDetector(false), errorOutput: $errorOutput);

        $exitCode = $redirector->redirect($config, ConsoleEntry::artisan('config:cache', null, ['/host/app/artisan', 'config:cache']), '/host/app', false);

        self::assertSame(0, $exitCode);
        self::assertSame("docker-composer: Running artisan config:cache in Docker Compose service php-tools.\n", $this->readErrorOutput($errorOutput));
        self::assertSame([
            ['docker', 'compose', 'up', '-d', 'php-tools'],
            [
                'docker',
                'compose',
                'exec',
                '-T',
                '--workdir',
                '/usr/src/app',
                '--env',
                'DOCKER_COMPOSER_INSIDE=1',
                'php-tools',
                '/usr/src/app/artisan',
                'config:cache',
            ],
        ], $runner->commands);
    }

    public function testRedirectNoticeKeepsEscapedConsoleTagsReadable(): void
    {
        $config = Config::fromArray([
            'enabled' => true,
            'service' => 'php<service>',
        ]);
        $runner = new MockProcessRunner();
        $builder = new DockerComposeCommandBuilder();
        $errorOutput = $this->createErrorOutput();
        $redirector = new Redirector(new DockerComposeRunner($runner, $builder), $builder, new MockContainerDetector(false), errorOutput: $errorOutput);

        $exitCode = $redirector->redirect($config, ConsoleEntry::artisan('bad<error>', null, ['artisan', 'bad<error>']), '/host/app', false);

        self::assertSame(0, $exitCode);
        self::assertSame("docker-composer: Running artisan bad<error> in Docker Compose service php<service>.\n", $this->readErrorOutput($errorOutput));
    }

    public function testReturnsNullWhenDisabledInsideContainerExcludedOrUnconfigured(): void
    {
        $entry = ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate']);
        $builder = new DockerComposeCommandBuilder();
        $errorOutput = $this->createErrorOutput();

        $disabledRunner = new MockProcessRunner();
        $disabled = Config::fromArray(['enabled' => false, 'service' => 'php']);
        self::assertNull((new Redirector(new DockerComposeRunner($disabledRunner, $builder), $builder, new MockContainerDetector(false), errorOutput: $errorOutput))->redirect($disabled, $entry, '/host/app', false));
        self::assertSame([], $disabledRunner->commands);

        $defaultOutputRunner = new MockProcessRunner();
        self::assertNull((new Redirector(new DockerComposeRunner($defaultOutputRunner, $builder), $builder, new MockContainerDetector(false)))->redirect($disabled, $entry, '/host/app', false));
        self::assertSame([], $defaultOutputRunner->commands);

        $insideRunner = new MockProcessRunner();
        $enabled = Config::fromArray(['enabled' => true, 'service' => 'php']);
        self::assertNull((new Redirector(new DockerComposeRunner($insideRunner, $builder), $builder, new MockContainerDetector(true), errorOutput: $errorOutput))->redirect($enabled, $entry, '/host/app', false));
        self::assertSame([], $insideRunner->commands);

        $excludedRunner = new MockProcessRunner();
        $excluded = Config::fromArray(['enabled' => true, 'service' => 'php', 'exclude' => ['migrate']]);
        self::assertNull((new Redirector(new DockerComposeRunner($excludedRunner, $builder), $builder, new MockContainerDetector(false), errorOutput: $errorOutput))->redirect($excluded, $entry, '/host/app', false));
        self::assertSame([], $excludedRunner->commands);

        $unconfiguredRunner = new MockProcessRunner();
        $unconfigured = Config::fromArray(['enabled' => true]);
        self::assertNull((new Redirector(new DockerComposeRunner($unconfiguredRunner, $builder), $builder, new MockContainerDetector(false), errorOutput: $errorOutput))->redirect($unconfigured, $entry, '/host/app', false));
        self::assertSame([], $unconfiguredRunner->commands);
        self::assertSame('', $this->readErrorOutput($errorOutput));
    }

    public function testRedirectSkipsEntrypointAbsolutizingWithoutPathMapping(): void
    {
        $projectRoot = $this->createProjectRootWithArtisan();
        $config = Config::fromArray([
            'enabled' => true,
            'service' => 'php',
        ]);
        $runner = new MockProcessRunner();
        $builder = new DockerComposeCommandBuilder();
        $redirector = new Redirector(new DockerComposeRunner($runner, $builder), $builder, new MockContainerDetector(false), errorOutput: $this->createErrorOutput());

        try {
            $exitCode = $redirector->redirect($config, ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate']), $projectRoot, false);
        } finally {
            $this->removeProjectRootWithArtisan($projectRoot);
        }

        self::assertSame(0, $exitCode);
        self::assertSame('artisan', $runner->commands[1][7]);
    }

    public function testRedirectAbsolutizesAndTranslatesEntrypointWithPathMapping(): void
    {
        $projectRoot = $this->createProjectRootWithArtisan();
        $config = Config::fromArray([
            'enabled' => true,
            'service' => 'php',
        ]);
        $configOutput = json_encode([
            'services' => [
                'php' => [
                    'volumes' => [
                        ['type' => 'bind', 'source' => $projectRoot, 'target' => '/usr/src/app'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $runner = new MockOutputCapturingProcessRunner([0, 0, 0], outputs: [$configOutput, "php\n"]);
        $builder = new DockerComposeCommandBuilder();
        $redirector = new Redirector(new DockerComposeRunner($runner, $builder), $builder, new MockContainerDetector(false), $runner, errorOutput: $this->createErrorOutput());

        try {
            $exitCode = $redirector->redirect($config, ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate']), $projectRoot, false);
        } finally {
            $this->removeProjectRootWithArtisan($projectRoot);
        }

        self::assertSame(0, $exitCode);
        self::assertSame('/usr/src/app/artisan', $runner->commands[2][9]);
    }

    #[BackupGlobals(true)]
    public function testEnvironmentDisableReturnsNull(): void
    {
        putenv('DOCKER_COMPOSER_DISABLE=1');

        try {
            $config = Config::fromArray([
                'enabled' => true,
                'service' => 'php',
            ]);
            $runner = new MockProcessRunner();
            $builder = new DockerComposeCommandBuilder();
            $errorOutput = $this->createErrorOutput();
            $redirector = new Redirector(new DockerComposeRunner($runner, $builder), $builder, new MockContainerDetector(false), errorOutput: $errorOutput);

            self::assertNull($redirector->redirect($config, ConsoleEntry::artisan('migrate', null, ['artisan', 'migrate']), '/host/app', false));
            self::assertSame([], $runner->commands);
            self::assertSame('', $this->readErrorOutput($errorOutput));
        } finally {
            putenv('DOCKER_COMPOSER_DISABLE');
        }
    }

    /**
     * @return resource
     */
    private function createErrorOutput()
    {
        $errorOutput = fopen('php://temp', 'w+');
        if ($errorOutput === false) {
            throw new \RuntimeException('Unable to create temporary error output stream.');
        }

        return $errorOutput;
    }

    /**
     * @param  resource  $errorOutput
     */
    private function readErrorOutput($errorOutput): string
    {
        rewind($errorOutput);

        return stream_get_contents($errorOutput) ?: '';
    }

    private function createProjectRootWithArtisan(): string
    {
        $projectRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'docker-composer-laravel-'
            . bin2hex(random_bytes(8));
        if (! mkdir($projectRoot, 0777, true) && ! is_dir($projectRoot)) {
            throw new \RuntimeException(sprintf('Unable to create test directory "%s".', $projectRoot));
        }

        file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'artisan', '');

        return $projectRoot;
    }

    private function removeProjectRootWithArtisan(string $projectRoot): void
    {
        @unlink($projectRoot . DIRECTORY_SEPARATOR . 'artisan');
        @rmdir($projectRoot);
    }
}
