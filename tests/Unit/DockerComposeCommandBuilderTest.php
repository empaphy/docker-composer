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
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;
use Tests\Unit\Mocks\InvalidLegacyTokenInput;
use Tests\Unit\Mocks\LegacyTokenInput;
use Tests\Unit\Mocks\RawTokenInput;

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

    public function testCommandBuilderUsesLegacyInputTokensProperty(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new LegacyTokenInput([
            '--no-interaction',
            'install',
            '--working-dir=app',
            '--prefer-dist',
        ]);

        $command = (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);

        self::assertSame([
            'composer',
            '--no-interaction',
            'install',
            '--prefer-dist',
        ], array_slice($command, -4));
    }

    public function testCommandBuilderUsesRawTokensMethod(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new RawTokenInput([
            '--no-interaction',
            'i',
            '--working-dir=/host/app',
            '--prefer-dist',
        ], 'i');

        $command = (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);

        self::assertSame([
            'composer',
            '--no-interaction',
            'install',
            '--prefer-dist',
        ], array_slice($command, -4));
    }

    public function testCommandBuilderStripsWorkingDirectoryTokenForms(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new LegacyTokenInput([
            '--working-dir',
            '/host/a',
            '-d',
            '/host/b',
            '-d/host/c',
            '--working-dir=/host/d',
            'install',
            '--',
            '-d',
            'vendor/package',
        ], 'install');

        $command = (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);

        self::assertSame([
            'composer',
            'install',
            '--',
            '-d',
            'vendor/package',
        ], array_slice($command, -5));
    }

    public function testCommandBuilderBuildsInteractiveRunCommand(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => [
                'service' => 'php',
                'mode' => 'run',
            ],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new LegacyTokenInput(['update'], 'update');

        $command = (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'update', $input, true);

        self::assertSame([
            'docker',
            'compose',
            'run',
            '--rm',
            '--env',
            'DOCKER_COMPOSER_INSIDE=1',
            'php',
            'composer',
            'update',
        ], $command);
    }

    public function testCommandBuilderUsesServerArgvFallback(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new ArrayInput(['install']);
        $previousServer = $_SERVER;

        try {
            $_SERVER['argv'] = ['composer', '--no-interaction', '--no-dev'];

            $command = (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);
        } finally {
            $_SERVER = $previousServer;
        }

        self::assertSame([
            'composer',
            '--no-interaction',
            '--no-dev',
            'install',
        ], array_slice($command, -4));
    }

    public function testCommandBuilderRejectsMissingRawTokens(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new ArrayInput(['install']);
        $previousServer = $_SERVER;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer command input must expose raw tokens.');

        try {
            $_SERVER = array_diff_key($_SERVER, ['argv' => true]);

            (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);
        } finally {
            $_SERVER = $previousServer;
        }
    }

    public function testCommandBuilderRejectsInvalidServerArgvTokens(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new ArrayInput(['install']);
        $previousServer = $_SERVER;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer command input must expose raw tokens.');

        try {
            $_SERVER['argv'] = ['composer', []];

            (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);
        } finally {
            $_SERVER = $previousServer;
        }
    }

    public function testCommandBuilderRejectsInvalidLegacyInputTokensProperty(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new InvalidLegacyTokenInput(['install', []]);

        self::assertSame(['install', []], $input->getTokensForAssertion());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer command input must expose raw tokens.');

        (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);
    }

    public function testCommandBuilderRejectsMalformedLegacyInputTokensProperty(): void
    {
        [$composer] = $this->createComposer([], [
            'docker-composer' => ['service' => 'php'],
        ]);
        $config = DockerComposerConfig::fromComposer($composer);
        $input = new InvalidLegacyTokenInput(['command' => 'install']);

        self::assertSame(['command' => 'install'], $input->getTokensForAssertion());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer command input must expose raw tokens.');

        (new DockerComposeCommandBuilder())->buildComposerCommand($config, 'install', $input, false);
    }
}
