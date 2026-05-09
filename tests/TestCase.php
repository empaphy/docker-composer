<?php

namespace Tests;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Symfony\Component\Console\Output\StreamOutput;

use function getcwd;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array<string, list<string>> $scripts
     * @param array<string, mixed>        $extra
     *
     * @return array{0: Composer, 1: BufferIO}
     */
    protected function createComposer(array $scripts, array $extra): array
    {
        $composer = new Composer();
        $package = new RootPackage('root/project', '1.0.0', '1.0.0');
        $package->setScripts($scripts);
        $package->setExtra($extra);
        $composer->setPackage($package);
        $composer->setConfig(new Config(false, getcwd() ?: null));

        $io = new BufferIO('', StreamOutput::VERBOSITY_NORMAL);
        $dispatcher = new EventDispatcher($composer, $io);
        $composer->setEventDispatcher($dispatcher);

        return [$composer, $io];
    }
}
