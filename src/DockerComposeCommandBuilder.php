<?php

/**
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\Script\Event as ScriptEvent;

final class DockerComposeCommandBuilder
{
    /**
     * @return list<string>
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
     * @return list<string>
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
     * @return list<string>
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
     * @return list<string>
     */
    private function composerRunScriptCommand(ScriptEvent $event): array
    {
        $command = [
            'composer',
            'run-script',
            $event->isDevMode() ? '--dev' : '--no-dev',
            $event->getName(),
        ];

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
     * @param mixed $argument
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

        throw new \InvalidArgumentException('Composer script arguments must be scalar values.');
    }
}
