<?php

/**
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use Composer\IO\BufferIO;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use empaphy\docker_composer\ComposerProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Unit\Mocks\MockProcessExecutor;

#[CoversClass(ComposerProcessRunner::class)]
class ComposerProcessRunnerTest extends TestCase
{
    public function testComposerProcessRunnerDelegatesToProcessExecutor(): void
    {
        $io = new BufferIO();
        $runner = new ComposerProcessRunner($io, static fn(): bool => true);
        $processExecutor = new MockProcessExecutor(3, 4, 'executor error');
        $property = new ReflectionProperty($runner, 'processExecutor');
        $property->setValue($runner, $processExecutor);

        self::assertTrue($runner->supportsTty());
        self::assertSame(3, $runner->run(['docker', 'compose']));
        self::assertSame(4, $runner->run(['docker', 'compose'], true));
        self::assertSame('executor error', $runner->getErrorOutput());
        $expectedCommand = implode(' ', array_map([ProcessExecutor::class, 'escape'], ['docker', 'compose']));

        self::assertSame([$expectedCommand], $processExecutor->commands);
        self::assertSame([$expectedCommand], $processExecutor->ttyCommands);
    }

    public function testComposerProcessRunnerCapturesOutput(): void
    {
        $io = new BufferIO();
        $runner = new ComposerProcessRunner($io, static fn(): bool => true);
        $processExecutor = new MockProcessExecutor(3, 4, 'executor error', 'captured output');
        $property = new ReflectionProperty($runner, 'processExecutor');
        $property->setValue($runner, $processExecutor);

        $output = '';

        self::assertSame(3, $runner->runWithOutput(['docker', 'compose'], $output));
        self::assertSame('captured output', $output);
    }

    public function testComposerProcessRunnerFallsBackWhenCurrentProcessDoesNotSupportTty(): void
    {
        $io = new BufferIO();
        $runner = new ComposerProcessRunner($io, static fn(): bool => false);
        $processExecutor = new MockProcessExecutor(3, 4, 'executor error');
        $property = new ReflectionProperty($runner, 'processExecutor');
        $property->setValue($runner, $processExecutor);

        self::assertFalse($runner->supportsTty());
        self::assertSame(3, $runner->run(['docker', 'compose'], true));
        $expectedCommand = implode(' ', array_map([ProcessExecutor::class, 'escape'], ['docker', 'compose']));

        self::assertSame([$expectedCommand], $processExecutor->commands);
        self::assertSame([], $processExecutor->ttyCommands);
    }

    public function testComposerProcessRunnerUsesComposerPlatformTtyDetection(): void
    {
        $method = new \ReflectionMethod(ComposerProcessRunner::class, 'detectTtySupport');

        self::assertSame(Platform::isTty(), $method->invoke(null));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testComposerProcessRunnerUsesStreamFallbackWithoutComposerPlatform(): void
    {
        $method = new \ReflectionMethod(ComposerProcessRunner::class, 'detectTtySupport');
        $autoloaders = spl_autoload_functions() ?: [];

        foreach ($autoloaders as $autoload) {
            spl_autoload_unregister($autoload);
        }

        try {
            $supportsTty = $method->invoke(null);
        } finally {
            foreach ($autoloaders as $autoload) {
                spl_autoload_register($autoload);
            }
        }

        self::assertSame(defined('STDOUT') && stream_isatty(STDOUT), $supportsTty);
    }
}
