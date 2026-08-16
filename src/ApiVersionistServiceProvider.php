<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Versionist\ApiVersionist\Commands\AuditCommand;
use Versionist\ApiVersionist\Commands\ChangelogCommand;
use Versionist\ApiVersionist\Commands\ListVersionsCommand;
use Versionist\ApiVersionist\Commands\MakeTransformerCommand;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;
use Versionist\ApiVersionist\Http\Concerns\HasApiVersion;
use Versionist\ApiVersionist\Http\Controllers\ChangelogController;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Middleware\ApiVersionMiddleware;
use Versionist\ApiVersionist\Pipeline\RequestUpgradePipeline;
use Versionist\ApiVersionist\Pipeline\ResponseDowngradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Version\VersionDetector;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/**
 * Binds all versioning services as singletons and registers middleware,
 * commands, route macros, and request macros.
 */
class ApiVersionistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            $this->configPath(),
            'api-versionist',
        );

        $this->registerTransformerRegistry();
        $this->registerVersionDetector();
        $this->registerVersionNegotiator();
        $this->registerPipelines();
        $this->registerManager();
    }

    public function boot(): void
    {
        $this->bootPublishables();
        $this->bootCommands();
        $this->bootMiddleware();
        $this->bootRouteMacro();
        $this->bootRequestMacros();
        $this->bootChangelogRoute();
    }

    private function registerTransformerRegistry(): void
    {
        $this->app->singleton(TransformerRegistry::class, function ($app): TransformerRegistry {
            $registry = new TransformerRegistry;

            $transformerClasses = $app['config']->get('api-versionist.transformers', []);

            $transformers = array_map(
                fn (string $class): VersionTransformerInterface => $app->make($class),
                $transformerClasses,
            );

            $registry->registerMany($transformers);

            return $registry;
        });
    }

    private function registerVersionDetector(): void
    {
        $this->app->singleton(VersionDetector::class, fn ($app): VersionDetector => new VersionDetector(
            $app['config']->get('api-versionist', []),
        ));
    }

    private function registerVersionNegotiator(): void
    {
        $this->app->singleton(VersionNegotiator::class, fn ($app): VersionNegotiator => new VersionNegotiator(
            $app->make(VersionDetector::class),
            $app->make(TransformerRegistry::class),
            $app['config']->get('api-versionist', []),
        ));
    }

    private function registerPipelines(): void
    {
        $this->app->singleton(RequestUpgradePipeline::class, fn ($app): RequestUpgradePipeline => new RequestUpgradePipeline(
            $app->make(TransformerRegistry::class),
        ));

        $this->app->singleton(ResponseDowngradePipeline::class, fn ($app): ResponseDowngradePipeline => new ResponseDowngradePipeline(
            $app->make(TransformerRegistry::class),
        ));
    }

    private function registerManager(): void
    {
        $this->app->singleton(ApiVersionistManager::class, fn ($app): ApiVersionistManager => new ApiVersionistManager(
            $app->make(VersionNegotiator::class),
            $app->make(RequestUpgradePipeline::class),
            $app->make(ResponseDowngradePipeline::class),
            $app->make(TransformerRegistry::class),
            $app->make(Dispatcher::class),
            $app['config']->get('api-versionist', []),
        ));

        $this->app->alias(ApiVersionistManager::class, 'api-versionist');
    }

    private function bootPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => $this->app->configPath('api-versionist.php'),
        ], 'api-versionist-config');

        $this->publishes([
            $this->stubsPath() => $this->app->basePath('stubs/api-versionist'),
        ], 'api-versionist-stubs');
    }

    private function bootCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ChangelogCommand::class,
            ListVersionsCommand::class,
            MakeTransformerCommand::class,
            AuditCommand::class,
        ]);
    }

    private function bootMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('api.version', ApiVersionMiddleware::class);
    }

    private function bootRouteMacro(): void
    {
        Route::macro('versioned', function (?string $method = null, ?string $uri = null, mixed $action = null) {
            if ($method !== null && $uri !== null && $action !== null) {
                return $this->match(
                    [strtoupper($method)],
                    $uri,
                    $action,
                )->middleware('api.version');
            }

            return $this->middleware('api.version');
        });
    }

    private function bootRequestMacros(): void
    {
        HasApiVersion::register();
    }

    /**
     * Registers the JSON changelog endpoint advertised by the
     * Link: rel="successor-version" header. Opt-in via config.
     */
    private function bootChangelogRoute(): void
    {
        // A cached route file already contains this route; registering it
        // again would duplicate the api-versionist.changelog name.
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $changelog = $this->app->make(ConfigRepository::class)->get('api-versionist.changelog', []);

        if (! is_array($changelog) || empty($changelog['enabled'])) {
            return;
        }

        $endpoint = $changelog['endpoint'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            return;
        }

        $middleware = $changelog['middleware'] ?? [];

        Route::get($endpoint, ChangelogController::class)
            ->name('api-versionist.changelog')
            ->middleware(is_array($middleware) ? $middleware : [$middleware]);
    }

    private function configPath(): string
    {
        return dirname(__DIR__) . '/config/api-versionist.php';
    }

    private function stubsPath(): string
    {
        return dirname(__DIR__) . '/stubs';
    }
}
