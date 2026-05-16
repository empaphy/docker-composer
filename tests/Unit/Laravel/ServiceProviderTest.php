<?php

/**
 * @noinspection StaticClosureCanBeUsedInspection
 */

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use Closure;
use empaphy\docker_composer\DockerComposeCommandBuilder;
use empaphy\docker_composer\DockerComposeRunner;
use empaphy\docker_composer\DockerComposeWorkdirResolver;
use empaphy\docker_composer\EnvironmentContainerDetector;
use empaphy\docker_composer\Laravel\Config;
use empaphy\docker_composer\Laravel\ConsoleEntry;
use empaphy\docker_composer\Laravel\Redirector;
use empaphy\docker_composer\Laravel\ServiceProvider;
use empaphy\docker_composer\ShellProcessRunner;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

#[CoversClass(ServiceProvider::class)]
#[UsesClass(Config::class)]
#[UsesClass(ConsoleEntry::class)]
#[UsesClass(Redirector::class)]
#[UsesClass(DockerComposeCommandBuilder::class)]
#[UsesClass(DockerComposeRunner::class)]
#[UsesClass(DockerComposeWorkdirResolver::class)]
#[UsesClass(EnvironmentContainerDetector::class)]
#[UsesClass(ShellProcessRunner::class)]
final class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        IlluminateServiceProvider::$publishes = [];
        IlluminateServiceProvider::$publishGroups = [];
        ArtisanApplication::forgetBootstrappers();
    }

    protected function tearDown(): void
    {
        IlluminateServiceProvider::$publishes = [];
        IlluminateServiceProvider::$publishGroups = [];
        ArtisanApplication::forgetBootstrappers();

        parent::tearDown();
    }

    public function testRegisterMergesPackageConfig(): void
    {
        $config = new FakeLaravelConfig([
            'docker_composer' => [
                'enabled' => true,
                'service' => 'php',
            ],
        ]);
        $provider = new ServiceProvider(new FakeLaravelApplication($config));

        $provider->register();

        $merged = $config->get('docker_composer');

        self::assertIsArray($merged);
        self::assertTrue($merged['enabled']);
        self::assertSame('php', $merged['service']);
        self::assertSame('exec', $merged['mode']);
        self::assertSame([], $merged['compose_files']);
        self::assertNull($merged['project_directory']);
        self::assertNull($merged['workdir']);
        self::assertSame([], $merged['exclude']);
        self::assertSame([], $merged['service_mapping']);
    }

    public function testBootPublishesConfigAndReturnsOutsideConsole(): void
    {
        $config = new FakeLaravelConfig();
        $app = new FakeLaravelApplication($config, runningInConsole: false, basePath: '/host/app');
        $provider = new ServiceProvider($app);

        $provider->boot();

        $source = dirname(__DIR__, 3) . '/config/docker_composer.php';

        self::assertSame(
            [$source => '/host/app/config/docker_composer.php'],
            IlluminateServiceProvider::pathsToPublish(ServiceProvider::class, 'docker-composer-config'),
        );
        self::assertSame([], $app->makeCalls);
    }

    public function testBootReturnsWhenServerArgumentsAreUnavailable(): void
    {
        $this->assertBootReturnsBeforeResolvingServices(null, hasServerArguments: false);
        $this->assertBootReturnsBeforeResolvingServices([]);
        $this->assertBootReturnsBeforeResolvingServices('artisan');
        $this->assertBootReturnsBeforeResolvingServices([1 => 'artisan']);
        $this->assertBootReturnsBeforeResolvingServices(['artisan', 1]);
    }

    public function testBootsNonArtisanScriptWithDisabledConfig(): void
    {
        $config = new FakeLaravelConfig([
            'docker_composer' => [
                'enabled' => false,
                'service' => 'php',
            ],
        ]);
        $app = new FakeLaravelApplication($config, runningInConsole: true, basePath: '/host/app');
        $exitCodes = [];
        $provider = new ServiceProvider($app, function (int $exitCode) use (&$exitCodes): void {
            $exitCodes[] = $exitCode;
        });

        $this->bootWithArguments($provider, ['/host/app/scripts/task.php', '--flag']);

        self::assertSame(['config'], $app->makeCalls);
        self::assertSame([], $exitCodes);
    }

    public function testBootReturnsWhenArtisanEventsBindingIsUnavailable(): void
    {
        $config = new FakeLaravelConfig([
            'docker_composer' => 'invalid',
        ]);
        $app = new FakeLaravelApplication($config, runningInConsole: true);
        $provider = new ServiceProvider($app);

        $this->bootWithArguments($provider, ['artisan', 'migrate']);

        self::assertSame(['config', 'events'], $app->makeCalls);
    }

    #[DataProvider('invalidEventDispatcherExamples')]
    public function testBootReturnsWhenArtisanEventsDispatcherIsInvalid(mixed $events): void
    {
        $config = new FakeLaravelConfig([
            'docker_composer' => [
                'enabled' => false,
                'service' => 'php',
            ],
        ]);
        $app = new FakeLaravelApplication($config, runningInConsole: true, events: $events, bindEvents: true);
        $provider = new ServiceProvider($app);

        $this->bootWithArguments($provider, ['artisan', 'migrate']);

        self::assertSame(['config', 'events'], $app->makeCalls);
    }

    public function testBootRegistersAndInvokesCommandStartingListener(): void
    {
        $events = new FakeEventsDispatcher();
        $kernel = new FakeLaravelKernel([
            'migrate' => new FakeListedArtisanCommand(),
        ]);
        $config = new FakeLaravelConfig([
            'docker_composer' => [
                'enabled' => false,
                'service' => 'php',
            ],
        ]);
        $app = new FakeLaravelApplication($config, runningInConsole: true, events: $events, bindEvents: true, kernel: $kernel, bindKernel: true);
        $exitCodes = [];
        $provider = new ServiceProvider($app, function (int $exitCode) use (&$exitCodes): void {
            $exitCodes[] = $exitCode;
        });

        $this->bootWithArguments($provider, ['/host/app/artisan', 'migrate']);

        $listeners = $events->listeners[CommandStarting::class] ?? [];
        self::assertCount(1, $listeners);

        $listeners[0](new CommandStarting('migrate', new ArrayInput([]), new NullOutput()));

        self::assertSame(1, $kernel->allCalls);
        self::assertSame([], $exitCodes);
    }

    public function testBootRegistersAndInvokesArtisanStartingCallback(): void
    {
        $events = new FakeEventsDispatcher();
        $config = new FakeLaravelConfig([
            'docker_composer' => [
                'enabled' => false,
                'service' => 'php',
            ],
        ]);
        $app = new FakeLaravelApplication($config, runningInConsole: true, events: $events, bindEvents: true);
        $exitCodes = [];
        $provider = new ServiceProvider($app, function (int $exitCode) use (&$exitCodes): void {
            $exitCodes[] = $exitCode;
        });

        $this->bootWithArguments($provider, ['/host/app/artisan', '--env=testing', 'migrate']);
        $bootstrappers = $this->getArtisanBootstrappers();

        self::assertCount(1, $bootstrappers);

        $bootstrappers[0](new \stdClass());

        self::assertSame([], $exitCodes);
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('commandNameArgumentExamples')]
    public function testGetsCommandNameFromArguments(array $arguments, ?string $expected): void
    {
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()));

        self::assertSame($expected, $this->invokeProviderMethod($provider, 'getCommandNameFromArguments', [$arguments]));
    }

    #[DataProvider('eventCommandExamples')]
    public function testGetsCommandNameFromCommandStartingEvent(string $command, ?string $expected): void
    {
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()));
        $event = new CommandStarting($command, new ArrayInput([]), new NullOutput());

        self::assertSame($expected, $this->invokeProviderMethod($provider, 'getEventCommandName', [$event]));
    }

    public function testGetsNullCommandNameFromUnsetCommandStartingEvent(): void
    {
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()));
        $event = new CommandStarting('migrate', new ArrayInput([]), new NullOutput());
        unset($event->command);

        self::assertNull($this->invokeProviderMethod($provider, 'getEventCommandName', [$event]));
    }

    public function testResolvesArtisanCommandClassFromKernelCommandList(): void
    {
        $kernel = new FakeLaravelKernel([
            'migrate' => new FakeListedArtisanCommand(),
        ]);
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig(), kernel: $kernel, bindKernel: true));

        self::assertSame(FakeListedArtisanCommand::class, $this->invokeProviderMethod($provider, 'resolveArtisanCommandClass', ['migrate']));
    }

    public function testResolvesArtisanCommandClassFromArtisanFind(): void
    {
        $artisan = new FakeArtisanApplication([
            'queue:work' => new FakeFoundArtisanCommand(),
        ]);
        $kernel = new FakeLaravelKernel([
            'migrate' => new FakeListedArtisanCommand(),
        ], $artisan);
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig(), kernel: $kernel, bindKernel: true));

        self::assertSame(FakeFoundArtisanCommand::class, $this->invokeProviderMethod($provider, 'resolveArtisanCommandClass', ['queue:work']));
        self::assertSame(1, $artisan->findCalls);
    }

    public function testArtisanCommandClassResolutionReturnsNullWhenNameIsMissing(): void
    {
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()));

        self::assertNull($this->invokeProviderMethod($provider, 'resolveArtisanCommandClass', [null]));
    }

    public function testArtisanCommandClassResolutionReturnsNullWhenKernelIsMissingOrInvalid(): void
    {
        $missingKernel = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()));
        $invalidKernel = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig(), kernel: 'invalid', bindKernel: true));

        self::assertNull($this->invokeProviderMethod($missingKernel, 'resolveArtisanCommandClass', ['migrate']));
        self::assertNull($this->invokeProviderMethod($invalidKernel, 'resolveArtisanCommandClass', ['migrate']));
    }

    public function testArtisanCommandClassResolutionReturnsNullWhenArtisanIsMissingOrInvalid(): void
    {
        $withoutArtisan = new ServiceProvider(new FakeLaravelApplication(
            new FakeLaravelConfig(),
            kernel: new FakeLaravelKernelWithoutArtisan(),
            bindKernel: true,
        ));
        $nonObjectArtisan = new ServiceProvider(new FakeLaravelApplication(
            new FakeLaravelConfig(),
            kernel: new FakeLaravelKernel(artisan: 'invalid'),
            bindKernel: true,
        ));
        $objectWithoutFind = new ServiceProvider(new FakeLaravelApplication(
            new FakeLaravelConfig(),
            kernel: new FakeLaravelKernel(artisan: new \stdClass()),
            bindKernel: true,
        ));
        $nonObjectCommand = new ServiceProvider(new FakeLaravelApplication(
            new FakeLaravelConfig(),
            kernel: new FakeLaravelKernel(artisan: new FakeArtisanApplication(['migrate' => 'invalid'])),
            bindKernel: true,
        ));

        self::assertNull($this->invokeProviderMethod($withoutArtisan, 'resolveArtisanCommandClass', ['migrate']));
        self::assertNull($this->invokeProviderMethod($nonObjectArtisan, 'resolveArtisanCommandClass', ['migrate']));
        self::assertNull($this->invokeProviderMethod($objectWithoutFind, 'resolveArtisanCommandClass', ['migrate']));
        self::assertNull($this->invokeProviderMethod($nonObjectCommand, 'resolveArtisanCommandClass', ['migrate']));
    }

    public function testArtisanCommandClassResolutionReturnsNullWhenResolutionThrows(): void
    {
        $throwingKernel = new ServiceProvider(new FakeLaravelApplication(
            new FakeLaravelConfig(),
            kernel: new ThrowingLaravelKernel(),
            bindKernel: true,
        ));
        $throwingArtisan = new ServiceProvider(new FakeLaravelApplication(
            new FakeLaravelConfig(),
            kernel: new FakeLaravelKernel(artisan: new FakeArtisanApplication(throws: true)),
            bindKernel: true,
        ));

        self::assertNull($this->invokeProviderMethod($throwingKernel, 'resolveArtisanCommandClass', ['migrate']));
        self::assertNull($this->invokeProviderMethod($throwingArtisan, 'resolveArtisanCommandClass', ['migrate']));
    }

    public function testExitIfRedirectedIgnoresNullExitCode(): void
    {
        $exitCodes = [];
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()), function (int $exitCode) use (&$exitCodes): void {
            $exitCodes[] = $exitCode;
        });

        $this->invokeProviderMethod($provider, 'exitIfRedirected', [null]);

        self::assertSame([], $exitCodes);
    }

    public function testExitIfRedirectedUsesInjectedTerminator(): void
    {
        $exitCodes = [];
        $provider = new ServiceProvider(new FakeLaravelApplication(new FakeLaravelConfig()), function (int $exitCode) use (&$exitCodes): void {
            $exitCodes[] = $exitCode;
        });

        $this->invokeProviderMethod($provider, 'exitIfRedirected', [17]);

        self::assertSame([17], $exitCodes);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidEventDispatcherExamples(): iterable
    {
        yield 'scalar' => ['invalid'];
        yield 'object without listen method' => [new \stdClass()];
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string|null}>
     */
    public static function commandNameArgumentExamples(): iterable
    {
        yield 'empty arguments' => [[], null];
        yield 'only entrypoint' => [['artisan'], null];
        yield 'argument separator' => [['artisan', '--', 'migrate'], null];
        yield 'options before command' => [['artisan', '--env=testing', '-v', '', 'migrate'], 'migrate'];
        yield 'first non-option argument' => [['artisan', 'about', '--json'], 'about'];
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function eventCommandExamples(): iterable
    {
        yield 'non-empty command' => ['migrate', 'migrate'];
        yield 'empty command' => ['', null];
    }

    /**
     * @param  mixed  $serverArguments
     *   The temporary `$_SERVER['argv']` value.
     */
    private function assertBootReturnsBeforeResolvingServices(mixed $serverArguments, bool $hasServerArguments = true): void
    {
        $this->withServerArguments($serverArguments, $hasServerArguments, function (): void {
            $app = new FakeLaravelApplication(new FakeLaravelConfig(), runningInConsole: true);
            $provider = new ServiceProvider($app);

            $provider->boot();

            self::assertSame([], $app->makeCalls);
        });
    }

    /**
     * @param  list<string>  $arguments
     *   The temporary server arguments.
     */
    private function bootWithArguments(ServiceProvider $provider, array $arguments): void
    {
        $this->withServerArguments($arguments, true, function () use ($provider): void {
            $provider->boot();
        });
    }

    /**
     * @param  mixed  $serverArguments
     *   The temporary `$_SERVER['argv']` value.
     *
     * @param  Closure(): void  $callback
     *   The callback to run with the temporary arguments.
     */
    private function withServerArguments(mixed $serverArguments, bool $hasServerArguments, Closure $callback): void
    {
        $hadOriginalArguments = array_key_exists('argv', $_SERVER);
        $originalArguments = $_SERVER['argv'] ?? null;

        try {
            if ($hasServerArguments) {
                $_SERVER['argv'] = $serverArguments;
            } else {
                unset($_SERVER['argv']);
            }

            $callback();
        } finally {
            if ($hadOriginalArguments) {
                $_SERVER['argv'] = $originalArguments;
            } else {
                unset($_SERVER['argv']);
            }
        }
    }

    /**
     * @param  list<mixed>  $arguments
     *   The private method arguments.
     */
    private function invokeProviderMethod(ServiceProvider $provider, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($provider, $method))->invoke($provider, ...$arguments);
    }

    /**
     * @return list<Closure>
     */
    private function getArtisanBootstrappers(): array
    {
        $property = (new ReflectionClass(ArtisanApplication::class))->getProperty('bootstrappers');
        $bootstrappers = $property->getValue();
        self::assertIsArray($bootstrappers);

        $callbacks = [];
        foreach ($bootstrappers as $bootstrapper) {
            self::assertInstanceOf(Closure::class, $bootstrapper);
            $callbacks[] = $bootstrapper;
        }

        return $callbacks;
    }
}

final class FakeLaravelApplication extends Container implements Application
{
    /**
     * Stores resolved container keys.
     *
     * @var list<string>
     */
    public array $makeCalls = [];

    public function __construct(
        FakeLaravelConfig $config,
        private readonly bool $runningInConsole = false,
        private readonly string $basePath = '/host/app',
        mixed $events = null,
        bool $bindEvents = false,
        mixed $kernel = null,
        bool $bindKernel = false,
    ) {
        $this->instance('config', $config);
        if ($bindEvents) {
            $this->instance('events', $events);
        }

        if ($bindKernel) {
            $this->instance(Kernel::class, $kernel);
        }
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function make($abstract, array $parameters = [])
    {
        $this->makeCalls[] = is_string($abstract) ? $abstract : get_debug_type($abstract);

        return parent::make($abstract, $parameters);
    }

    public function version(): string
    {
        return 'testing';
    }

    public function basePath($path = ''): string
    {
        return $this->path($this->basePath, $path);
    }

    public function bootstrapPath($path = ''): string
    {
        return $this->path($this->basePath . '/bootstrap', $path);
    }

    public function configPath($path = ''): string
    {
        return $this->path($this->basePath . '/config', $path);
    }

    public function databasePath($path = ''): string
    {
        return $this->path($this->basePath . '/database', $path);
    }

    public function langPath($path = ''): string
    {
        return $this->path($this->basePath . '/lang', $path);
    }

    public function publicPath($path = ''): string
    {
        return $this->path($this->basePath . '/public', $path);
    }

    public function resourcePath($path = ''): string
    {
        return $this->path($this->basePath . '/resources', $path);
    }

    public function storagePath($path = ''): string
    {
        return $this->path($this->basePath . '/storage', $path);
    }

    /**
     * @param  string|array<mixed>  ...$environments
     */
    public function environment(...$environments): string|bool
    {
        if ($environments === []) {
            return 'testing';
        }

        $expected = [];
        foreach ($environments as $environment) {
            foreach ((array) $environment as $name) {
                $expected[] = $name;
            }
        }

        return in_array('testing', $expected, true);
    }

    public function runningInConsole(): bool
    {
        return $this->runningInConsole;
    }

    public function runningUnitTests(): bool
    {
        return true;
    }

    public function hasDebugModeEnabled(): bool
    {
        return false;
    }

    public function maintenanceMode(): MaintenanceMode
    {
        return new FakeMaintenanceMode();
    }

    public function isDownForMaintenance(): bool
    {
        return false;
    }

    public function registerConfiguredProviders(): void {}

    public function register($provider, $force = false): IlluminateServiceProvider
    {
        if ($provider instanceof IlluminateServiceProvider) {
            return $provider;
        }

        if (is_string($provider) && is_a($provider, IlluminateServiceProvider::class, true)) {
            return new $provider($this);
        }

        throw new \InvalidArgumentException('Expected a service provider instance or class name.');
    }

    public function registerDeferredProvider($provider, $service = null): void {}

    public function resolveProvider($provider): IlluminateServiceProvider
    {
        if (is_string($provider) && is_a($provider, IlluminateServiceProvider::class, true)) {
            return new $provider($this);
        }

        throw new \InvalidArgumentException('Expected a service provider class name.');
    }

    public function boot(): void {}

    public function booting($callback): void {}

    public function booted($callback): void {}

    /**
     * @param  array<mixed>  $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void {}

    public function getLocale(): string
    {
        return 'en';
    }

    public function getNamespace(): string
    {
        return 'Tests\\';
    }

    /**
     * @return list<IlluminateServiceProvider>
     */
    public function getProviders($provider): array
    {
        return [];
    }

    public function hasBeenBootstrapped(): bool
    {
        return false;
    }

    public function loadDeferredProviders(): void {}

    public function setLocale($locale): void {}

    public function shouldSkipMiddleware(): bool
    {
        return false;
    }

    public function terminating($callback): Application
    {
        return $this;
    }

    public function terminate(): void {}

    private function path(string $base, mixed $path): string
    {
        $path = is_string($path) ? $path : '';

        return $base . ($path === '' ? '' : '/' . $path);
    }
}

final class FakeMaintenanceMode implements MaintenanceMode
{
    /**
     * @param  array<mixed>  $payload
     */
    public function activate(array $payload): void {}

    public function deactivate(): void {}

    public function active(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}

final class FakeLaravelConfig
{
    /**
     * Stores fake Laravel configuration values.
     *
     * @param  array<string, mixed>  $values
     *   The initial config values.
     */
    public function __construct(private array $values = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}

final class FakeEventsDispatcher
{
    /**
     * Stores listeners by event class.
     *
     * @var array<string, list<Closure>>
     */
    public array $listeners = [];

    public function listen(string $event, Closure $listener): void
    {
        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $listener;
    }
}

abstract class FakeLaravelKernelBase
{
    /**
     * @return array<string, mixed>
     */
    abstract public function all(): array;
}

final class FakeLaravelKernel extends FakeLaravelKernelBase
{
    public int $allCalls = 0;

    /**
     * @param  array<string, mixed>  $commands
     */
    public function __construct(private readonly array $commands = [], private readonly mixed $artisan = null) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->allCalls++;

        return $this->commands;
    }

    public function getArtisan(): mixed
    {
        return $this->artisan;
    }
}

final class FakeLaravelKernelWithoutArtisan extends FakeLaravelKernelBase
{
    /**
     * @param  array<string, mixed>  $commands
     */
    public function __construct(private readonly array $commands = []) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->commands;
    }
}

final class ThrowingLaravelKernel extends FakeLaravelKernelBase
{
    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        throw new \RuntimeException('Kernel command resolution failed.');
    }
}

final class FakeArtisanApplication
{
    public int $findCalls = 0;

    /**
     * @param  array<string, mixed>  $commands
     */
    public function __construct(private readonly array $commands = [], private readonly bool $throws = false) {}

    public function find(string $commandName): mixed
    {
        $this->findCalls++;
        if ($this->throws) {
            throw new \RuntimeException('Artisan command resolution failed.');
        }

        return $this->commands[$commandName] ?? null;
    }
}

final class FakeListedArtisanCommand {}

final class FakeFoundArtisanCommand {}
