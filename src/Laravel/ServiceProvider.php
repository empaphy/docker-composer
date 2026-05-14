<?php

/**
 * Defines Laravel package integration.
 *
 * @copyright 2026 The Empaphy Project
 * @author    Alwin Garside <alwin@garsi.de>
 * @license   MIT
 * @package   DockerComposer
 */

declare(strict_types=1);

namespace empaphy\docker_composer\Laravel;

use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeRunner;
use empaphy\docker_composer\EnvironmentContainerDetector;
use empaphy\docker_composer\ShellProcessRunner;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use Throwable;

/**
 * Registers Laravel console Docker redirection through package autodiscovery.
 */
final class ServiceProvider extends IlluminateServiceProvider
{
    /**
     * Registers package configuration defaults.
     *
     * @return void
     *   Returns nothing.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/docker_composer.php', 'docker_composer');
    }

    /**
     * Boots Laravel Docker redirection for console execution.
     *
     * @return void
     *   Returns nothing.
     */
    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 2) . '/config/docker_composer.php' => $this->app->configPath('docker_composer.php'),
        ], 'docker-composer-config');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $arguments = $this->getServerArguments();
        if ($arguments === []) {
            return;
        }

        $projectRoot = $this->app->basePath();
        $config = Config::fromArray($this->getConfig());
        $redirector = $this->createRedirector();
        if ($this->isArtisan($arguments[0])) {
            $this->listenForArtisanCommands($config, $redirector, $arguments, $projectRoot);

            return;
        }

        $this->exitIfRedirected($redirector->redirect(
            $config,
            ConsoleEntry::script(ConsoleEntry::scriptName($arguments[0], $projectRoot), $arguments),
            $projectRoot,
            $this->isInteractive(),
        ));
    }

    /**
     * Gets package configuration from Laravel.
     *
     * @return array<mixed>
     *   Returns the `docker_composer` config array.
     */
    private function getConfig(): array
    {
        $config = $this->app->make('config')->get('docker_composer', []);

        return is_array($config) ? $config : [];
    }

    /**
     * Creates the Laravel console redirector.
     *
     * @return Redirector
     *   Returns the configured redirector.
     */
    private function createRedirector(): Redirector
    {
        $processRunner = new ShellProcessRunner();
        $commandBuilder = new DockerComposeCommandBuilder();

        return new Redirector(
            new DockerComposeRunner($processRunner, $commandBuilder),
            $commandBuilder,
            new EnvironmentContainerDetector(),
            $processRunner,
        );
    }

    /**
     * Registers the command-starting listener for Artisan commands.
     *
     * @param  Config  $config
     *   The Laravel Docker configuration.
     *
     * @param  Redirector  $redirector
     *   The redirector used to execute Docker commands.
     *
     * @param  list<string>  $arguments
     *   The raw CLI arguments to replay in Docker.
     *
     * @param  string  $projectRoot
     *   The absolute Laravel project root on the host.
     *
     * @return void
     *   Returns nothing.
     */
    private function listenForArtisanCommands(Config $config, Redirector $redirector, array $arguments, string $projectRoot): void
    {
        $artisanClass = 'Illuminate\Console\Application';
        if (is_callable([$artisanClass, 'starting'])) {
            call_user_func([$artisanClass, 'starting'], function (object $artisan) use ($config, $redirector, $arguments, $projectRoot): void {
                $commandName = $this->getCommandNameFromArguments($arguments);
                $this->exitIfRedirected($redirector->redirect(
                    $config,
                    ConsoleEntry::artisan($commandName, null, $arguments),
                    $projectRoot,
                    $this->isInteractive(),
                ));
            });
        }

        $events = $this->app->make('events');
        if (! is_object($events) || ! method_exists($events, 'listen')) {
            return;
        }

        $events->listen(CommandStarting::class, function (CommandStarting $event) use ($config, $redirector, $arguments, $projectRoot): void {
            $commandName = $this->getEventCommandName($event);
            $this->exitIfRedirected($redirector->redirect(
                $config,
                ConsoleEntry::artisan($commandName, $this->resolveArtisanCommandClass($commandName), $arguments),
                $projectRoot,
                $this->isInteractive(),
            ));
        });
    }

    /**
     * Gets the Artisan command name from raw CLI arguments.
     *
     * @param  list<string>  $arguments
     *   The raw CLI arguments.
     *
     * @return string|null
     *   Returns the command name, or `null` when unavailable.
     */
    private function getCommandNameFromArguments(array $arguments): ?string
    {
        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--') {
                return null;
            }

            if ($argument !== '' && ! str_starts_with($argument, '-')) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Gets the Artisan command name from a Laravel command event.
     *
     * @param  CommandStarting  $event
     *   The event.
     *
     * @return string|null
     *   Returns the command name, or `null` when unavailable.
     */
    private function getEventCommandName(CommandStarting $event): ?string
    {
        $command = $event->command ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }

    /**
     * Resolves an Artisan command name to its command class.
     *
     * @param  string|null  $commandName
     *   The Artisan command name, or `null` when unavailable.
     *
     * @return class-string|null
     *   Returns the command class, or `null` when resolution fails.
     */
    private function resolveArtisanCommandClass(?string $commandName): ?string
    {
        if ($commandName === null) {
            return null;
        }

        try {
            $kernel = $this->app->make(Kernel::class);
            if (! is_object($kernel)) {
                return null;
            }

            foreach ($kernel->all() as $name => $command) {
                if ($name === $commandName && is_object($command)) {
                    return $command::class;
                }
            }

            if (! method_exists($kernel, 'getArtisan')) {
                return null;
            }

            $artisan = $kernel->getArtisan();
            if (! is_object($artisan) || ! method_exists($artisan, 'find')) {
                return null;
            }

            $command = $artisan->find($commandName);

            return is_object($command) ? $command::class : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Gets raw CLI arguments from `$_SERVER`.
     *
     * @return list<string>
     *   Returns `argv` as a list of strings, or an empty list when unavailable.
     */
    private function getServerArguments(): array
    {
        if (! isset($_SERVER['argv']) || ! is_array($_SERVER['argv']) || ! array_is_list($_SERVER['argv'])) {
            return [];
        }

        $arguments = [];
        foreach ($_SERVER['argv'] as $argument) {
            if (! is_string($argument)) {
                return [];
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * Checks whether the entrypoint is Laravel's Artisan file.
     *
     * @param  string  $entrypoint
     *   The first CLI argument.
     *
     * @return bool
     *   Returns `true` when __entrypoint__ names `artisan`.
     */
    private function isArtisan(string $entrypoint): bool
    {
        return basename(str_replace('\\', '/', $entrypoint)) === 'artisan';
    }

    /**
     * Checks whether the current process can run interactively.
     *
     * @return bool
     *   Returns `true` when STDIN is a terminal.
     */
    private function isInteractive(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    /**
     * Exits the host process if a Docker redirect occurred.
     *
     * @param  int|null  $exitCode
     *   The Docker exit code, or `null` when no redirection happened.
     *
     * @return void
     *   Returns nothing.
     */
    private function exitIfRedirected(?int $exitCode): void
    {
        if ($exitCode !== null) {
            exit($exitCode);
        }
    }
}
