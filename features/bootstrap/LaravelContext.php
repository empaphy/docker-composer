<?php

/**
 * @noinspection PhpIllegalPsrClassPathInspection
 */

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Defines executable Laravel integration feature steps for Docker-Composer.
 */
class LaravelContext implements Context
{
    use InteractsWithTemporaryProjects;

    #[Given('a Laravel project')]
    public function createLaravelProjectFixture(): void
    {
        $this->projectDirectory = $this->createLaravelProject();
    }

    #[When('I configure Laravel Docker-Composer redirection')]
    public function configureLaravelDockerComposerRedirection(): void
    {
        $this->writeLaravelDockerComposerConfig($this->getProjectDirectory());
    }

    #[Then('the Laravel package should autodiscover the Docker-Composer service provider')]
    public function assertLaravelPackageAutodiscoversDockerComposerServiceProvider(): void
    {
        $packagesPath = $this->getProjectDirectory() . '/bootstrap/cache/packages.php';

        Assert::assertFileExists($packagesPath);

        $packages = require $packagesPath;
        if (! is_array($packages)) {
            throw new RuntimeException(sprintf('Expected "%s" to return an array.', $packagesPath));
        }

        $package = $packages['empaphy/docker-composer'] ?? null;
        if (! is_array($package)) {
            throw new RuntimeException('Expected the Docker-Composer package to be discovered.');
        }

        $providers = $package['providers'] ?? null;
        if (! is_array($providers)) {
            throw new RuntimeException('Expected the Docker-Composer package to define providers.');
        }

        Assert::assertContains('empaphy\\docker_composer\\Laravel\\ServiceProvider', $providers);
    }

    #[Then('the Laravel Docker-Composer configuration should exist')]
    public function assertLaravelDockerComposerConfigurationShouldExist(): void
    {
        Assert::assertFileExists($this->getProjectDirectory() . '/config/docker_composer.php');
    }

    private function createLaravelProject(): string
    {
        $projectDirectory = $this->createTemporaryProjectDirectory('docker-composer-laravel-integration-');
        foreach ([
            'app/Console/Commands',
            'app/Exceptions',
            'app/Console',
            'bootstrap/cache',
            'config',
            'scripts',
        ] as $directory) {
            $path = $projectDirectory . '/' . $directory;
            if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s".', $path));
            }
        }

        $this->writeJson($projectDirectory . '/composer.json', [
            'name' => 'empaphy/docker-composer-laravel-integration',
            'description' => 'Temporary docker-composer Laravel integration fixture.',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => [[
                'type' => 'path',
                'url' => dirname(__DIR__, 2),
                'options' => ['symlink' => false],
            ]],
            'require' => [
                'laravel/framework' => '^12.0',
                'empaphy/docker-composer' => '*',
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                ],
            ],
            'config' => [
                'allow-plugins' => [
                    'empaphy/docker-composer' => true,
                ],
            ],
            'scripts' => [
                'post-autoload-dump' => [
                    'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                    '@php artisan package:discover --ansi',
                ],
            ],
        ]);

        file_put_contents($projectDirectory . '/artisan', <<<'PHP'
#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

define('LARAVEL_START', microtime(true));

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$input = new ArgvInput();
$status = $kernel->handle($input, new ConsoleOutput());
$kernel->terminate($input, $status);

exit($status);
PHP);
        chmod($projectDirectory . '/artisan', 0755);

        file_put_contents($projectDirectory . '/bootstrap/app.php', <<<'PHP'
<?php

use App\Console\Kernel;
use App\Exceptions\Handler;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;

$app = new Application(dirname(__DIR__));
$app->singleton(KernelContract::class, Kernel::class);
$app->singleton(ExceptionHandler::class, Handler::class);

return $app;
PHP);

        file_put_contents($projectDirectory . '/config/app.php', <<<'PHP'
<?php

use Illuminate\Support\ServiceProvider;

return [
    'name' => 'Docker-Composer Test',
    'env' => 'testing',
    'debug' => true,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'cipher' => 'AES-256-CBC',
    'providers' => ServiceProvider::defaultProviders()->toArray(),
];
PHP);

        file_put_contents($projectDirectory . '/app/Console/Kernel.php', <<<'PHP'
<?php

namespace App\Console;

use App\Console\Commands\ClassMappedCommand;
use App\Console\Commands\HostOnlyCommand;
use App\Console\Commands\MarkCommand;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * @var list<class-string>
     */
    protected $commands = [
        MarkCommand::class,
        ClassMappedCommand::class,
        HostOnlyCommand::class,
    ];
}
PHP);

        file_put_contents($projectDirectory . '/app/Exceptions/Handler.php', <<<'PHP'
<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
}
PHP);

        file_put_contents($projectDirectory . '/app/Console/Commands/MarkCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarkCommand extends Command
{
    protected $signature = 'mark';

    protected $description = 'Mark the current execution environment.';

    public function handle(): int
    {
        file_put_contents(base_path('result.txt'), getenv('DOCKER_COMPOSER_TEST_MARK') ?: (getenv('DOCKER_COMPOSER_INSIDE') ?: 'host'));

        return self::SUCCESS;
    }
}
PHP);

        file_put_contents($projectDirectory . '/app/Console/Commands/ClassMappedCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ClassMappedCommand extends Command
{
    protected $signature = 'class-map';

    protected $description = 'Mark class mapped execution.';

    public function handle(): int
    {
        file_put_contents(base_path('class.txt'), getenv('DOCKER_COMPOSER_TEST_MARK') ?: (getenv('DOCKER_COMPOSER_INSIDE') ?: 'host'));

        return self::SUCCESS;
    }
}
PHP);

        file_put_contents($projectDirectory . '/app/Console/Commands/HostOnlyCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HostOnlyCommand extends Command
{
    protected $signature = 'host-only';

    protected $description = 'Mark host-only execution.';

    public function handle(): int
    {
        file_put_contents(base_path('host.txt'), getenv('DOCKER_COMPOSER_INSIDE') ?: 'host');

        return self::SUCCESS;
    }
}
PHP);

        file_put_contents($projectDirectory . '/scripts/bootstrap.php', <<<'PHP'
#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents(__DIR__ . '/../script.txt', getenv('DOCKER_COMPOSER_TEST_MARK') ?: (getenv('DOCKER_COMPOSER_INSIDE') ?: 'host'));
PHP);
        chmod($projectDirectory . '/scripts/bootstrap.php', 0755);

        file_put_contents($projectDirectory . '/docker-compose.yaml', sprintf(<<<'YAML'
services:
  php:
    image: %s
    command: ['sleep', 'infinity']
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
  php_tools:
    image: %s
    command: ['sleep', 'infinity']
    environment:
      DOCKER_COMPOSER_TEST_MARK: mapped
    working_dir: /usr/src/app
    volumes:
      - { type: bind, source: '.', target: '/usr/src/app' }
YAML, $this->getComposerImage(), $this->getComposerImage()));

        return $projectDirectory;
    }

    private function writeLaravelDockerComposerConfig(string $projectDirectory): void
    {
        file_put_contents($projectDirectory . '/config/docker_composer.php', <<<'PHP'
<?php

return [
    'enabled' => env('DOCKER_COMPOSER_LARAVEL', false),
    'service' => 'php',
    'mode' => 'exec',
    'compose_files' => 'docker-compose.yaml',
    'workdir' => '/usr/src/app',
    'exclude' => ['host-only'],
    'service_mapping' => [
        'php_tools' => [
            App\Console\Commands\ClassMappedCommand::class,
            ':scripts/bootstrap.php',
        ],
    ],
];
PHP);
    }
}
