<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeExecutionResult;
use empaphy\docker_composer\DockerComposerConfig;
use empaphy\docker_composer\DockerComposeResolvedOptions;
use empaphy\docker_composer\DockerComposeRunner;
use empaphy\docker_composer\DockerComposeWorkdirResolution;
use empaphy\docker_composer\DockerComposeWorkdirResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;
use Tests\Unit\Mocks\MockOutputCapturingProcessRunner;
use Tests\Unit\Mocks\MockProcessRunner;

#[CoversClass(DockerComposeWorkdirResolver::class)]
#[CoversClass(DockerComposeWorkdirResolution::class)]
#[CoversClass(DockerComposeResolvedOptions::class)]
#[UsesClass(DockerComposeCommandBuilder::class)]
#[UsesClass(DockerComposeRunner::class)]
#[UsesClass(DockerComposeExecutionResult::class)]
#[UsesClass(DockerComposerConfig::class)]
final class DockerComposeWorkdirResolverTest extends TestCase
{
    public function testVolumeMappingUsesExactSourceBeforeAncestor(): void
    {
        $runner = new MockOutputCapturingProcessRunner(outputs: [$this->composeOutput([
            'volumes' => [
                ['type' => 'bind', 'source' => '/host', 'target' => '/container'],
                ['type' => 'bind', 'source' => '/host/app', 'target' => '/usr/src/app'],
            ],
        ])]);
        $config = $this->createConfig(['service' => 'php']);

        $resolution = $this->createResolver()->resolve($config, '/host/app', $runner, $this->createRunner($runner));

        self::assertSame('/usr/src/app', $resolution->getWorkdir());
        self::assertSame('/usr/src/app', $resolution->getContainerWorkingDirectory());
        self::assertSame('/usr/src/app', $resolution->getContainerProjectRoot());
        self::assertTrue($resolution->hasPathMapping());
        self::assertSame([['docker', 'compose', 'config', '--format', 'json']], $runner->commands);
    }

    public function testVolumeMappingUsesLongestAncestorSource(): void
    {
        $runner = new MockOutputCapturingProcessRunner(outputs: [$this->composeOutput([
            'volumes' => [
                ['type' => 'bind', 'source' => '/host', 'target' => '/container'],
                ['type' => 'bind', 'source' => '/host/other', 'target' => '/other'],
            ],
        ])]);
        $config = $this->createConfig(['service' => 'php']);

        $resolution = $this->createResolver()->resolve($config, '/host/app/package', $runner, $this->createRunner($runner));

        self::assertSame('/container/app/package', $resolution->getWorkdir());
        self::assertSame('/container/app/package', $resolution->getContainerWorkingDirectory());
    }

    public function testExplicitWorkdirIsAuthoritativeAndFallbackMapping(): void
    {
        $runner = new MockOutputCapturingProcessRunner(outputs: [$this->composeOutput([
            'volumes' => [
                ['type' => 'bind', 'source' => '/host/app', 'target' => '/mounted'],
            ],
        ])]);
        $config = $this->createConfig([
            'service' => 'php',
            'workdir' => '/configured',
        ]);

        $resolution = $this->createResolver()->resolve($config, '/host/app', $runner, $this->createRunner($runner));

        self::assertSame('/configured', $resolution->getWorkdir());
        self::assertSame('/mounted', $resolution->getContainerWorkingDirectory());

        $fallback = $this->createResolver()->resolve($config, '/host/app', new MockProcessRunner());

        self::assertSame('/configured', $fallback->getWorkdir());
        self::assertSame('/configured', $fallback->getContainerWorkingDirectory());
    }

    public function testComposeWorkingDirSetsWorkdirWithoutPathMapping(): void
    {
        $runner = new MockOutputCapturingProcessRunner(outputs: [$this->composeOutput([
            'working_dir' => '/srv/app',
        ])]);
        $config = $this->createConfig(['service' => 'php']);

        $resolution = $this->createResolver()->resolve($config, '/host/app', $runner, $this->createRunner($runner));

        self::assertSame('/srv/app', $resolution->getWorkdir());
        self::assertNull($resolution->getContainerWorkingDirectory());
        self::assertFalse($resolution->hasPathMapping());
    }

    public function testExecModeProbesServiceWorkdir(): void
    {
        $runner = new MockOutputCapturingProcessRunner(
            [0, 0, 0, 0],
            outputs: [$this->composeOutput([]), '', "/pwd\n"],
        );
        $config = $this->createConfig(['service' => 'php']);

        $resolution = $this->createResolver()->resolve($config, '/host/app', $runner, $this->createRunner($runner));

        self::assertSame('/pwd', $resolution->getWorkdir());
        self::assertNull($resolution->getContainerWorkingDirectory());
        self::assertSame(['config', 'ps', 'up', 'exec'], [
            $runner->commands[0][2],
            $runner->commands[1][2],
            $runner->commands[2][2],
            $runner->commands[3][2],
        ]);
        self::assertSame(['docker', 'compose', 'exec', '-T', 'php', 'pwd'], $runner->commands[3]);
    }

    public function testRunModeProbesOneOffServiceWorkdir(): void
    {
        $runner = new MockOutputCapturingProcessRunner(
            [0, 0],
            outputs: [$this->composeOutput([]), "/run-pwd\n"],
        );
        $config = $this->createConfig([
            'service' => 'php',
            'mode' => 'run',
        ]);

        $resolution = $this->createResolver()->resolve($config, '/host/app', $runner, $this->createRunner($runner));

        self::assertSame('/run-pwd', $resolution->getWorkdir());
        self::assertSame(['docker', 'compose', 'run', '--rm', '-T', 'php', 'pwd'], $runner->commands[1]);
    }

    public function testImageWorkdirFallbackIgnoresEmptyImageWorkdir(): void
    {
        $config = $this->createConfig([
            'service' => 'php',
            'mode' => 'run',
        ]);
        $resolvedRunner = new MockOutputCapturingProcessRunner(
            [0, 1, 0],
            outputs: [$this->composeOutput(['image' => 'php:cli']), '', "/image\n"],
        );

        $resolved = $this->createResolver()->resolve($config, '/host/app', $resolvedRunner, $this->createRunner($resolvedRunner));

        self::assertSame('/image', $resolved->getWorkdir());
        self::assertSame(['docker', 'image', 'inspect', '--format', '{{.Config.WorkingDir}}', 'php:cli'], $resolvedRunner->commands[2]);

        $emptyRunner = new MockOutputCapturingProcessRunner(
            [0, 1, 0],
            outputs: [$this->composeOutput(['image' => 'php:cli']), '', ''],
        );

        $empty = $this->createResolver()->resolve($config, '/host/app', $emptyRunner, $this->createRunner($emptyRunner));

        self::assertNull($empty->getWorkdir());
        self::assertNull($empty->getContainerWorkingDirectory());
    }

    public function testResolvedOptionsDelegateExceptWorkdir(): void
    {
        $config = $this->createConfig([
            'service' => 'php',
            'mode' => 'run',
            'compose-files' => 'compose.yaml',
            'project-directory' => '.',
        ]);
        $options = new DockerComposeResolvedOptions($config, '/resolved');

        self::assertSame('php', $options->getService());
        self::assertSame('run', $options->getMode());
        self::assertSame(['compose.yaml'], $options->getComposeFiles());
        self::assertSame('.', $options->getProjectDirectory());
        self::assertSame('/resolved', $options->getWorkdir());
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function composeOutput(array $service): string
    {
        return json_encode(['services' => ['php' => $service]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createConfig(array $options): DockerComposerConfig
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => $options,
        ]);

        return DockerComposerConfig::fromComposer($composer);
    }

    private function createResolver(): DockerComposeWorkdirResolver
    {
        return new DockerComposeWorkdirResolver(new DockerComposeCommandBuilder());
    }

    private function createRunner(MockOutputCapturingProcessRunner $runner): DockerComposeRunner
    {
        return new DockerComposeRunner($runner, new DockerComposeCommandBuilder());
    }
}
