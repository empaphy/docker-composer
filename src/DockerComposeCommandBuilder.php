<?php

/**
 * Defines Docker Compose command construction.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Builds Docker Compose commands for redirected Composer scripts.
 */
class DockerComposeCommandBuilder
{
    /**
     * Builds the Docker Compose service startup command.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that provides service options.
     *
     * @return list<string>
     *   Returns command arguments for `docker compose up -d`.
     */
    public function buildUpCommand(DockerComposerConfig $config): array
    {
        return array_merge($this->composeBase($config), [
            'up',
            '-d',
            $config->getService(),
        ]);
    }

    /**
     * Builds the Docker Compose running services command.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that provides service options.
     *
     * @return list<string>
     *   Returns command arguments for `docker compose ps`.
     */
    public function buildRunningServicesCommand(DockerComposerConfig $config): array
    {
        return array_merge(
            $this->composeBase($config),
            ['ps', '--status', 'running', '--services', $config->getService()],
        );
    }

    /**
     * Builds the Docker Compose script execution command.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that provides service options.
     *
     * @param  ScriptEvent  $event
     *   The Composer script event to replay inside Docker Compose.
     *
     * @param  bool  $interactive
     *   Whether the Docker command should keep TTY interaction enabled.
     *
     * @return list<string>
     *   Returns command arguments for `docker compose exec` or `run`.
     */
    public function buildScriptCommand(DockerComposerConfig $config, ScriptEvent $event, bool $interactive): array
    {
        $command = $this->composeBase($config);
        $command[] = $config->getMode();

        if ($config->getMode() === DockerComposerConfig::MODE_RUN) {
            $command[] = '--rm';
        }

        if (! $interactive) {
            $command[] = '-T';
        }

        if ($config->getWorkdir() !== null) {
            $command[] = '--workdir';
            $command[] = $config->getWorkdir();
        }

        $command[] = '--env';
        $command[] = 'DOCKER_COMPOSER_INSIDE=1';
        $command[] = $config->getService();

        return array_merge($command, $this->composerRunScriptCommand($event));
    }

    /**
     * Builds the Docker Compose Composer command execution command.
     *
     * @param  DockerComposerConfig  $config
     *   The Docker Composer configuration that provides service options.
     *
     * @param  string  $commandName
     *   The Composer command name to replay inside Docker Compose.
     *
     * @param  InputInterface  $input
     *   The original console input whose raw arguments are replayed.
     *
     * @param  bool  $interactive
     *   Whether the Docker command should keep TTY interaction enabled.
     *
     * @return list<string>
     *   Returns command arguments for `docker compose exec` or `run`.
     */
    public function buildComposerCommand(DockerComposerConfig $config, string $commandName, InputInterface $input, bool $interactive): array
    {
        $command = $this->composeBase($config);
        $command[] = $config->getMode();

        if ($config->getMode() === DockerComposerConfig::MODE_RUN) {
            $command[] = '--rm';
        }

        if (! $interactive) {
            $command[] = '-T';
        }

        if ($config->getWorkdir() !== null) {
            $command[] = '--workdir';
            $command[] = $config->getWorkdir();
        }

        $command[] = '--env';
        $command[] = 'DOCKER_COMPOSER_INSIDE=1';
        $command[] = $config->getService();
        $command[] = 'composer';

        return array_merge($command, $this->getCommandArguments($input, $commandName));
    }

    /**
     * Builds the common Docker Compose command prefix.
     *
     * @param  DockerComposerConfig  $config
     *   The configuration that provides compose files and project directory.
     *
     * @return list<string>
     *   Returns base arguments beginning with `docker compose`.
     */
    private function composeBase(DockerComposerConfig $config): array
    {
        $command = ['docker', 'compose'];

        foreach ($config->getComposeFiles() as $composeFile) {
            $command[] = '--file';
            $command[] = $composeFile;
        }

        if ($config->getProjectDirectory() !== null) {
            $command[] = '--project-directory';
            $command[] = $config->getProjectDirectory();
        }

        return $command;
    }

    /**
     * Builds the Composer run-script command for the container.
     *
     * @param  ScriptEvent  $event
     *   The script event whose name, flags, and arguments are replayed.
     *
     * @return list<string>
     *   Returns command arguments beginning with `composer run-script`.
     */
    private function composerRunScriptCommand(ScriptEvent $event): array
    {
        $command = [
            'composer',
            'run-script',
        ];

        if (! $event->getIO()->isInteractive()) {
            $command[] = '--no-interaction';
        }

        $command[] = $event->isDevMode() ? '--dev' : '--no-dev';
        $command[] = sprintf('--timeout=%d', ProcessExecutor::getTimeout());
        $command[] = $event->getName();

        $arguments = $event->getArguments();
        if ($arguments === []) {
            return $command;
        }

        $command[] = '--';
        foreach ($arguments as $argument) {
            $command[] = $this->stringifyArgument($argument);
        }

        return $command;
    }

    /**
     * Gets raw Composer command arguments with host working directory removed.
     *
     * @param  InputInterface  $input
     *   The input that may expose raw console tokens.
     *
     * @param  string  $commandName
     *   The Composer command name to replace the first argument.
     *
     * @return list<string>
     *   Returns Composer arguments including the command name.
     */
    private function getCommandArguments(InputInterface $input, string $commandName): array
    {
        $tokens = $this->getRawInputTokens($input);
        $firstArgument = $input->getFirstArgument();
        $commandTokenReplaced = false;

        foreach ($tokens as $index => $token) {
            if (! $commandTokenReplaced && $token === $firstArgument) {
                $tokens[$index] = $commandName;
                $commandTokenReplaced = true;
            }
        }

        if (! $commandTokenReplaced) {
            $tokens[] = $commandName;
        }

        return $this->stripWorkingDirectoryTokens($tokens);
    }

    /**
     * Gets raw input tokens across Symfony Console versions.
     *
     * @param  InputInterface  $input
     *   The console input that carries Composer command arguments.
     *
     * @return list<string>
     *   Returns raw tokens without the Composer executable.
     *
     * @throws InvalidArgumentException
     *   Thrown when raw tokens cannot be recovered safely.
     */
    private function getRawInputTokens(InputInterface $input): array
    {
        if (method_exists($input, 'getRawTokens')) {
            /** @var list<string> $tokens */
            $tokens = $input->getRawTokens();

            return $tokens;
        }

        $tokens = $this->getRawInputTokensFromProperty($input);
        if ($tokens !== null) {
            return $tokens;
        }

        $argv = $_SERVER['argv'] ?? null;
        if (! is_array($argv) || $argv === []) {
            throw new InvalidArgumentException('Composer command input must expose raw tokens.');
        }

        $tokens = [];
        foreach (array_slice($argv, 1) as $token) {
            if (! is_string($token)) {
                throw new InvalidArgumentException('Composer command input must expose raw tokens.');
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Gets raw input tokens from legacy Symfony Console internals.
     *
     * @param  InputInterface  $input
     *   The console input that may store raw tokens privately.
     *
     * @return list<string>|null
     *   Returns raw tokens, or `null` when no compatible property exists.
     *
     * @throws InvalidArgumentException
     *   Thrown when the legacy tokens property has an unexpected shape.
     */
    private function getRawInputTokensFromProperty(InputInterface $input): ?array
    {
        $reflection = new \ReflectionObject($input);
        if (! $reflection->hasProperty('tokens')) {
            return null;
        }

        $property = $reflection->getProperty('tokens');
        $rawTokens = $property->getValue($input);
        if (! is_array($rawTokens) || ! array_is_list($rawTokens)) {
            throw new InvalidArgumentException('Composer command input must expose raw tokens.');
        }

        $tokens = [];
        foreach ($rawTokens as $token) {
            if (! is_string($token)) {
                throw new InvalidArgumentException('Composer command input must expose raw tokens.');
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Removes host-only Composer working directory options from arguments.
     *
     * @param  list<string>  $tokens
     *   The raw command arguments.
     *
     * @return list<string>
     *   Returns __tokens__ without `--working-dir` or `-d`.
     */
    private function stripWorkingDirectoryTokens(array $tokens): array
    {
        $stripped = [];
        $afterOptions = false;
        $skipNext = false;

        foreach ($tokens as $token) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ($afterOptions) {
                $stripped[] = $token;

                continue;
            }

            if ($token === '--') {
                $afterOptions = true;
                $stripped[] = $token;

                continue;
            }

            if ($token === '--working-dir' || $token === '-d') {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($token, '--working-dir=') || preg_match('/^-d.+/', $token) === 1) {
                continue;
            }

            $stripped[] = $token;
        }

        return $stripped;
    }

    /**
     * Converts a Composer script argument to a command string.
     *
     * @param  mixed  $argument
     *   The script argument provided by Composer.
     *
     * @return string
     *   Returns the scalar value converted for the shell command array.
     *
     * @throws InvalidArgumentException
     *   Thrown when __argument__ is not `null`, `bool`, or scalar.
     */
    private function stringifyArgument($argument): string
    {
        if ($argument === null) {
            return '';
        }

        if (is_bool($argument)) {
            return $argument ? '1' : '0';
        }

        if (is_scalar($argument)) {
            return (string) $argument;
        }

        throw new InvalidArgumentException('Composer script arguments must be scalar values.');
    }
}
