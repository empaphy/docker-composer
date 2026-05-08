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
