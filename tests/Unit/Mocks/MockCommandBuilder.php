<?php

declare(strict_types=1);

namespace Tests\Unit\Mocks;

use Composer\Script\Event as ScriptEvent;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposerConfig;

final class MockCommandBuilder extends DockerComposeCommandBuilder
{
    public function buildRunningServicesCommand(DockerComposerConfig $config): array
    {
        return ['php', '-r', 'exit(1);'];
    }

    public function buildUpCommand(DockerComposerConfig $config): array
    {
        return ['php', '-r', 'exit(0);'];
    }

    public function buildScriptCommand(DockerComposerConfig $config, ScriptEvent $event, bool $interactive): array
    {
        return ['php', '-r', 'exit(0);'];
    }
}
