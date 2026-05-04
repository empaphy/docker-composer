<?php

/**
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

interface ProcessRunner
{
    /**
     * @param list<string> $command
     */
    public function run(array $command, bool $tty = false): int;

    public function getErrorOutput(): string;

    public function supportsTty(): bool;
}
