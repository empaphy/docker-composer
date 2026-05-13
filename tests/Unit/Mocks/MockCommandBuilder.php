<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Composer\Script\Event as ScriptEvent;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeOptions;

final class MockCommandBuilder extends DockerComposeCommandBuilder
{
    public function buildRunningServicesCommand(DockerComposeOptions $config): array
    {
        return ['php', '-r', 'exit(1);'];
    }

    public function buildUpCommand(DockerComposeOptions $config): array
    {
        return ['php', '-r', 'exit(0);'];
    }

    public function buildConfigCommand(DockerComposeOptions $config): array
    {
        return ['php', '-r', 'echo \'{"services":{"php":{"working_dir":"/usr/src/app"}}}\';'];
    }

    public function buildScriptCommand(DockerComposeOptions $config, ScriptEvent $event, bool $interactive): array
    {
        return ['php', '-r', 'exit(0);'];
    }
}
