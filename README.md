# docker-composer

Composer plugin that ensures scripts are always executed within a Docker Compose service.

## Installation

```bash
composer require --dev empaphy/docker-composer
composer config allow-plugins.empaphy/docker-composer true
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
            "exclude": ["host-only-script"]
        }
    }
}
```

Only `service` is required for Docker redirection. If `service` is missing, the
plugin warns once per Composer run and lets host scripts run normally.

Supported keys:

- `service`: Docker Compose service used to run Composer scripts.
- `mode`: `exec` or `run`; defaults to `exec`.
- `compose-files`: one compose file path or a list of compose file paths.
- `project-directory`: optional Docker Compose project directory.
- `workdir`: optional working directory inside the container.
- `exclude`: exact Composer script/event names that should run on the host.

Unknown keys warn and are ignored. Invalid known values fail before Docker is run.

## Behavior

When Composer dispatches a top-level script on the host, the plugin runs the same
script inside the configured service and prevents the host-side script from
continuing.

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

Set `DOCKER_COMPOSER_DISABLE=1` to bypass Docker redirection temporarily.

## Scope

This plugin redirects Composer scripts, including lifecycle scripts such as
`post-install-cmd` and custom scripts run through `composer run-script`.

It does not transparently replace whole Composer commands such as
`composer install` with `docker compose exec php composer install`. Composer's
plugin command events do not provide a clean way to run a child command, skip the
host command, and return the child exit code without relying on fragile internals.
