<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(DockerComposeCommandBuilder::class)]
#[UsesClass(DockerComposerConfig::class)]
class DockerComposeCommandBuilderTest extends TestCase
{
    public function testCommandBuilderStringifiesNullAndBoolArguments(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $event = new ScriptEvent('test', $composer, $io, false, [null, true]);

        $command = (new DockerComposeCommandBuilder())->buildScriptCommand($config, $event, false);

        self::assertSame(['--', '', '1'], array_slice($command, -3));
    }

    public function testCommandBuilderForwardsComposerProcessTimeout(): void
    {
        [$composer, $io] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $previousTimeout = ProcessExecutor::getTimeout();

        ProcessExecutor::setTimeout(42);
        try {
            $config = DockerComposerConfig::fromComposer($composer);
            $event = new ScriptEvent('test', $composer, $io);

            $command = (new DockerComposeCommandBuilder())->buildScriptCommand($config, $event, false);
        } finally {
            ProcessExecutor::setTimeout($previousTimeout);
        }

        self::assertSame('--timeout=42', $command[count($command) - 2]);
    }

    public function testCommandBuilderRejectsNonScalarArguments(): void
    {
        $method = new \ReflectionMethod(DockerComposeCommandBuilder::class, 'stringifyArgument');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer script arguments must be scalar values.');

        $method->invoke(new DockerComposeCommandBuilder(), []);
    }
}
