<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit;

use empaphy\docker_composer\ShellProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ShellProcessRunner::class)]
final class ShellProcessRunnerTest extends TestCase
{
    public function testRunWithOutputCapturesStdoutAndExitCode(): void
    {
        $runner = new ShellProcessRunner();
        $output = '';

        $exitCode = $runner->runWithOutput([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "captured output\n");',
        ], $output);

        self::assertSame(0, $exitCode);
        self::assertSame("captured output\n", $output);
        self::assertSame('', $runner->getErrorOutput());
    }

    public function testRunReturnsExitCodeAndCapturesStderr(): void
    {
        $runner = new ShellProcessRunner();

        $exitCode = $runner->run([
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "captured error\n"); exit(7);',
        ]);

        self::assertSame(7, $exitCode);
        self::assertSame("captured error\n", $runner->getErrorOutput());
    }

    public function testRunWithOutputReturnsFailureWhenProcessCannotStart(): void
    {
        $runner = new ShellProcessRunner(self::failToOpenProcess(...));
        $output = 'previous output';

        $exitCode = $runner->runWithOutput([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "unreachable output\n");',
        ], $output);

        self::assertSame(1, $exitCode);
        self::assertSame('', $output);
        self::assertSame('Unable to start process.', $runner->getErrorOutput());
    }

    public function testSupportsTtyReturnsFalse(): void
    {
        self::assertFalse((new ShellProcessRunner())->supportsTty());
    }

    /**
     * Fakes a failed process startup.
     *
     * @param  list<string>  $command
     *   The command that would have been executed.
     *
     * @param  array<int, mixed>  $descriptors
     *   The process descriptors that would have been used.
     *
     * @param  array<int, resource>  $pipes
     *   The process pipe resources.
     *
     * @return false
     *   Always returns `false` to mimic `proc_open` startup failure.
     */
    private static function failToOpenProcess(array $command, array $descriptors, array &$pipes): mixed
    {
        unset($command, $descriptors);

        $pipes = [];

        return false;
    }
}
