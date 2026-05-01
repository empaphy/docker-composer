<?php

/**
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 *
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */

declare(strict_types=1);

namespace empaphy\docker_composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * An Example.
 */
class DockerComposerPlugin implements EventSubscriberInterface, PluginInterface
{
    /**
     * Apply plugin modifications to Composer
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement activate() method.
    }

    /**
     * Remove any hooks from Composer
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement deactivate() method.
    }

    /**
     * Prepare the plugin to be uninstalled
     *
     * This will be called after deactivate.
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement uninstall() method.
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *   - The method name to call (priority defaults to 0)
     *   - An array composed of the method name to call and the priority
     *   - An array of arrays composed of the method names to call and
     *     respective priorities, or 0 if unset
     *
     * For instance:
     *
     *   - array('eventName' => 'methodName')
     *   - array('eventName' => array('methodName', $priority))
     *   - array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array<string, string|array{0: string, 1?: int}|array<array{0: string, 1?: int}>>
     *   The event names to listen to
     */
    public static function getSubscribedEvents(): array
    {
        // TODO: Implement getSubscribedEvents() method.
    }
}
