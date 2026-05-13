# docker-composer

Composer plugin that ensures scripts are always executed within a Docker Compose service.

## Installation

```bash
composer config allow-plugins.empaphy/docker-composer true
composer require --dev empaphy/docker-composer
```

Composer 2.2 and newer require plugins to be allowed explicitly. Composer 1 ignores
`allow-plugins`.

## Configuration

Configure the target service in the root package's `composer.json`:

```json
{
    "extra": {
        "docker-composer": {
            "service": "php",
            "mode": "exec",
            "compose-files": ["docker-compose.yaml"],
            "project-directory": ".",
            "workdir": "/usr/src/app",
            "exclude": ["host-only-script"],
            "service-mapping": {
                "php-test": "test",
                "php-tools": ["stan", "cs"]
            }
        }
    }
}
```

Configure `service` for the default redirection target, and configure
`service-mapping` for scripts that should run in a different service. If neither
resolves the current script, the plugin warns once per Composer run and lets host
scripts run normally.

Supported keys:

- `service`: Docker Compose service used to run Composer scripts.
- `mode`: `exec` or `run`; defaults to `exec`.
- `compose-files`: one compose file path or a list of compose file paths.
- `project-directory`: optional Docker Compose project directory.
- `workdir`: optional working directory inside the container. When omitted, the plugin attempts to infer it.
- `exclude`: exact Composer script/event names that should run on the host.
- `service-mapping`: Docker Compose service names mapped to one script or a list of scripts.

Unknown keys warn and are ignored. Invalid known values fail before Docker is run.

## Behavior

When Composer dispatches a top-level script on the host, the plugin runs the same
script inside the configured service and prevents the host-side script from
continuing.

If `service-mapping` contains the current script name under a service, that service is used
instead of the default `service`.

In `exec` mode, the plugin checks whether the service is already running:

```bash
docker compose ps --status running --services <service>
```

If the service is not listed, it starts it:

```bash
docker compose up -d <service>
docker compose exec <service> composer run-script <script>
```

In `run` mode, it runs:

```bash
docker compose run --rm <service> composer run-script <script>
```

The plugin passes `DOCKER_COMPOSER_INSIDE=1` into the container. Composer scripts
then run normally because the plugin detects that Composer is already inside a
container. It also treats `/.dockerenv`, `/run/.containerenv`, and common cgroup
markers as container signals.

When `workdir` is omitted, the plugin attempts to infer the host project root's
container path from Docker Compose bind volumes. If no mapping is found, it
falls back to configured service `working_dir`, probing `pwd`, then image
`Config.WorkingDir`. Path translation only runs when a host-to-container
mapping is known.

Set `DOCKER_COMPOSER_DISABLE=1` to bypass Docker redirection temporarily.

## Scope

This plugin redirects Composer scripts, including lifecycle scripts such as
`post-install-cmd` and custom scripts run through `composer run-script`.

It also redirects dependency commands before host execution so platform
requirements are resolved from inside the configured service:

- `composer install`
- `composer update`
- `composer require`
- `composer remove`
- `composer reinstall`

## Laravel

The package also registers a Laravel service provider through package
autodiscovery. Publish and enable the Laravel config:

```bash
php artisan vendor:publish --tag=docker-composer-config
```

```php
return [
    'enabled' => env('DOCKER_COMPOSER_LARAVEL', false),
    'service' => 'php',
    'mode' => 'exec',
    'compose_files' => ['docker-compose.yaml'],
    'project_directory' => '.',
    'workdir' => '/usr/src/app',
    'exclude' => ['queue:work'],
    'service_mapping' => [
        'php-tools' => [
            'config:cache',
            Illuminate\Foundation\Console\ConfigCacheCommand::class,
            ':scripts/task.php',
        ],
    ],
];
```

When enabled, Laravel CLI bootstraps run in Docker Compose unless excluded.
Artisan commands can be mapped by command name or command class. Custom scripts
that bootstrap Laravel can be mapped by project-relative path prefixed with `:`.

The Laravel integration preserves the original CLI arguments and translates
absolute host project paths to the configured container `workdir`.
