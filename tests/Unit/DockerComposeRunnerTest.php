<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeExecutionResult;
use empaphy\docker_composer\DockerComposeOptions;
use empaphy\docker_composer\DockerComposeRunner;
use empaphy\docker_composer\DockerComposerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Tests\Unit\Mocks\MockOutputCapturingProcessRunner;
use Tests\Unit\Mocks\MockProcessRunner;

#[CoversClass(DockerComposeRunner::class)]
#[CoversClass(DockerComposeExecutionResult::class)]
#[CoversClass(DockerComposeCommandBuilder::class)]
#[CoversClass(DockerComposerConfig::class)]
final class DockerComposeRunnerTest extends TestCase
{
    public function testExecModeStartsServiceOnce(): void
    {
        $config = $this->createConfig(['service' => 'php']);
        $processRunner = new MockProcessRunner();
        $runner = new DockerComposeRunner($processRunner, new DockerComposeCommandBuilder());

        $first = $runner->run($config, ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'], false);
        $second = $runner->run($config, ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'], false);

        self::assertTrue($first->isSuccessful());
        self::assertTrue($second->isSuccessful());
        self::assertSame([
            ['docker', 'compose', 'up', '-d', 'php'],
            ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'],
            ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'],
        ], $processRunner->commands);
    }

    public function testExecModeSkipsStartupWhenServiceIsRunning(): void
    {
        $config = $this->createConfig(['service' => 'php']);
        $processRunner = new MockOutputCapturingProcessRunner(outputs: ['php']);
        $runner = new DockerComposeRunner($processRunner, new DockerComposeCommandBuilder());

        $result = $runner->run($config, ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'], false);

        self::assertTrue($result->isSuccessful());
        self::assertSame([
            ['docker', 'compose', 'ps', '--status', 'running', '--services', 'php'],
            ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'],
        ], $processRunner->commands);
    }

    public function testStartupFailureReturnsFailedResult(): void
    {
        $config = $this->createConfig(['service' => 'php']);
        $processRunner = new MockProcessRunner([7]);
        $runner = new DockerComposeRunner($processRunner, new DockerComposeCommandBuilder());

        $result = $runner->run($config, ['docker', 'compose', 'exec', '-T', 'php', 'php', '-v'], false);

        self::assertFalse($result->isSuccessful());
        self::assertSame('up', $result->getPhase());
        self::assertSame(7, $result->getExitCode());
        self::assertSame(['docker', 'compose', 'up', '-d', 'php'], $result->getCommand());
        self::assertSame([
            ['docker', 'compose', 'up', '-d', 'php'],
        ], $processRunner->commands);
    }

    public function testRunModeDoesNotStartService(): void
    {
        $config = $this->createConfig([
            'service' => 'php',
            'mode' => DockerComposeOptions::MODE_RUN,
        ]);
        $processRunner = new MockProcessRunner();
        $runner = new DockerComposeRunner($processRunner, new DockerComposeCommandBuilder());

        $result = $runner->run($config, ['docker', 'compose', 'run', '--rm', '-T', 'php', 'php', '-v'], false);

        self::assertSame('run', $result->getPhase());
        self::assertSame([
            ['docker', 'compose', 'run', '--rm', '-T', 'php', 'php', '-v'],
        ], $processRunner->commands);
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
}
