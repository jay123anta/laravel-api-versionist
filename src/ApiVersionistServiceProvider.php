<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;
use Versionist\ApiVersionist\Http\Concerns\HasApiVersion;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Middleware\ApiVersionMiddleware;
use Versionist\ApiVersionist\Pipeline\RequestUpgradePipeline;
use Versionist\ApiVersionist\Pipeline\ResponseDowngradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Commands\AuditCommand;
use Versionist\ApiVersionist\Commands\ChangelogCommand;
use Versionist\ApiVersionist\Commands\ListVersionsCommand;
use Versionist\ApiVersionist\Commands\MakeTransformerCommand;
use Versionist\ApiVersionist\Version\VersionDetector;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/**
 * Wires the entire API Versionist package into the Laravel container.
 *
 * ## Registration phase (`register()`)
 *
 * Binds all core services as singletons so the same instance is reused
 * throughout a request lifecycle:
 *
 * - TransformerRegistry — populated from the `transformers` config array
 * - VersionDetector — configured from the package config
 * - VersionNegotiator — depends on detector + registry + config
 * - RequestUpgradePipeline / ResponseDowngradePipeline — depend on registry
 * - ApiVersionistManager — orchestrates all of the above
 *
 * ## Boot phase (`boot()`)
 *
 * - Publishes config under the `api-versionist-config` tag
 * - Publishes stubs under the `api-versionist-stubs` tag
 * - Registers the `api.version` middleware alias
 * - Registers the `Route::versioned()` macro
 * - Registers Request macros via HasApiVersion
 *
 * ## Auto-discovery
 *
 * The `extra.laravel` section in composer.json ensures this provider
 * and the ApiVersionist facade are auto-discovered — no manual
 * registration required.
 */
class ApiVersionistServiceProvider extends ServiceProvider
{
    /**
     * Register package services into the container.
     *
     * All bindings are singletons to ensure consistent state across
     * a single request. The TransformerRegistry is eagerly populated
     * from the `transformers` config array during binding resolution.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge package defaults so the app config overrides package defaults.
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

    /**
     * Boot package services after all providers have registered.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->bootPublishables();
        $this->bootCommands();
        $this->bootMiddleware();
        $this->bootRouteMacro();
        $this->bootRequestMacros();
    }

    // ──────────────────────────────────────────────────────────
    //  Registration: Container Bindings
    // ──────────────────────────────────────────────────────────

    /**
     * Register the TransformerRegistry as a singleton.
     *
     * Reads the `transformers` config key — an array of fully-qualified
     * class names — resolves each from the container, and registers them
     * into the registry via registerMany().
     *
     * @return void
     */
    private function registerTransformerRegistry(): void
    {
        $this->app->singleton(TransformerRegistry::class, function ($app): TransformerRegistry {
            $registry = new TransformerRegistry();

            /** @var array<int, class-string<VersionTransformerInterface>> $transformerClasses */
            $transformerClasses = $app['config']->get('api-versionist.transformers', []);

            // Resolve each transformer class from the container so they
            // can themselves have injected dependencies if needed.
            $transformers = array_map(
                fn (string $class): VersionTransformerInterface => $app->make($class),
                $transformerClasses,
            );

            $registry->registerMany($transformers);

            return $registry;
        });
    }

    /**
     * Register the VersionDetector as a singleton.
     *
     * Receives the full package config array — not the facade — keeping
     * the detector testable without a running Laravel application.
     *
     * @return void
     */
    private function registerVersionDetector(): void
    {
        $this->app->singleton(VersionDetector::class, function ($app): VersionDetector {
            return new VersionDetector(
                $app['config']->get('api-versionist', []),
            );
        });
    }

    /**
     * Register the VersionNegotiator as a singleton.
     *
     * Depends on VersionDetector, TransformerRegistry, and config.
     *
     * @return void
     */
    private function registerVersionNegotiator(): void
    {
        $this->app->singleton(VersionNegotiator::class, function ($app): VersionNegotiator {
            return new VersionNegotiator(
                $app->make(VersionDetector::class),
                $app->make(TransformerRegistry::class),
                $app['config']->get('api-versionist', []),
            );
        });
    }

    /**
     * Register both pipeline classes as singletons.
     *
     * Both depend solely on the TransformerRegistry.
     *
     * @return void
     */
    private function registerPipelines(): void
    {
        $this->app->singleton(RequestUpgradePipeline::class, function ($app): RequestUpgradePipeline {
            return new RequestUpgradePipeline(
                $app->make(TransformerRegistry::class),
            );
        });

        $this->app->singleton(ResponseDowngradePipeline::class, function ($app): ResponseDowngradePipeline {
            return new ResponseDowngradePipeline(
                $app->make(TransformerRegistry::class),
            );
        });
    }

    /**
     * Register the ApiVersionistManager as a singleton.
     *
     * This is the central orchestrator and the target of the Facade.
     * All dependencies are pulled from the container.
     *
     * @return void
     */
    private function registerManager(): void
    {
        $this->app->singleton(ApiVersionistManager::class, function ($app): ApiVersionistManager {
            return new ApiVersionistManager(
                $app->make(VersionNegotiator::class),
                $app->make(RequestUpgradePipeline::class),
                $app->make(ResponseDowngradePipeline::class),
                $app->make(TransformerRegistry::class),
                $app->make(Dispatcher::class),
                $app['config']->get('api-versionist', []),
            );
        });

        // Alias the manager so it can be resolved by its short name.
        $this->app->alias(ApiVersionistManager::class, 'api-versionist');
    }

    // ──────────────────────────────────────────────────────────
    //  Boot: Publishables, Middleware, Macros
    // ──────────────────────────────────────────────────────────

    /**
     * Register publishable config and stub files.
     *
     * @return void
     */
    private function bootPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config file: php artisan vendor:publish --tag=api-versionist-config
        $this->publishes([
            $this->configPath() => $this->app->configPath('api-versionist.php'),
        ], 'api-versionist-config');

        // Stubs: php artisan vendor:publish --tag=api-versionist-stubs
        $this->publishes([
            $this->stubsPath() => $this->app->basePath('stubs/api-versionist'),
        ], 'api-versionist-stubs');
    }

    /**
     * Register Artisan commands when running in the console.
     *
     * @return void
     */
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

    /**
     * Register the api.version middleware alias.
     *
     * Compatible with both Laravel 10 (Router-based) and Laravel 11
     * (Kernel-based) middleware alias registration.
     *
     * @return void
     */
    private function bootMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('api.version', ApiVersionMiddleware::class);
    }

    /**
     * Register the Route::versioned() macro.
     *
     * Supports two invocation modes:
     *
     * ```php
     * // Mode 1: Route group — returns a PendingResourceRegistration-like builder
     * Route::versioned()->group(function () {
     *     Route::get('/users', [UserController::class, 'index']);
     * });
     *
     * // Mode 2: Single route shorthand
     * Route::versioned('GET', '/users', [UserController::class, 'index']);
     * ```
     *
     * Both modes apply the `api.version` middleware automatically.
     *
     * @return void
     */
    private function bootRouteMacro(): void
    {
        Route::macro('versioned', function (?string $method = null, ?string $uri = null, mixed $action = null) {
            /** @var \Illuminate\Routing\Router $this */
            if ($method !== null && $uri !== null && $action !== null) {
                // Mode 2: Single route shorthand.
                // Register the route with the api.version middleware directly.
                return $this->match(
                    (array) strtoupper($method),
                    $uri,
                    $action,
                )->middleware('api.version');
            }

            // Mode 1: Return the router with api.version middleware
            // pre-applied — the caller chains ->group() on it.
            return $this->middleware('api.version');
        });
    }

    /**
     * Register Request macros for clean controller access.
     *
     * @return void
     */
    private function bootRequestMacros(): void
    {
        HasApiVersion::register();
    }

    // ──────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Return the absolute path to the package config file.
     *
     * @return string
     */
    private function configPath(): string
    {
        return dirname(__DIR__) . '/config/api-versionist.php';
    }

    /**
     * Return the absolute path to the package stubs directory.
     *
     * @return string
     */
    private function stubsPath(): string
    {
        return dirname(__DIR__) . '/stubs';
    }
}
