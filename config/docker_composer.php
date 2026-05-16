<?php

declare(strict_types=1);

return [
    'enabled' => env('DOCKER_COMPOSER_LARAVEL', false),
    'service' => null,
    'mode' => 'exec',
    'compose_files' => [],
    'project_directory' => null,
    'workdir' => null,
    'exclude' => [],
    'service_mapping' => [],
];
