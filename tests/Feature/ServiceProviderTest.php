<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Versionist\ApiVersionist\Facades\ApiVersionist;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Middleware\ApiVersionMiddleware;
use Versionist\ApiVersionist\Pipeline\RequestUpgradePipeline;
use Versionist\ApiVersionist\Pipeline\ResponseDowngradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Tests\TestCase;
use Versionist\ApiVersionist\Version\VersionDetector;
use Versionist\ApiVersionist\Version\VersionNegotiator;

class ServiceProviderTest extends TestCase
{
    public function test_transformer_registry_is_singleton(): void
    {
        $a = $this->app->make(TransformerRegistry::class);
        $b = $this->app->make(TransformerRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_version_detector_is_singleton(): void
    {
        $a = $this->app->make(VersionDetector::class);
        $b = $this->app->make(VersionDetector::class);

        $this->assertSame($a, $b);
    }

    public function test_version_negotiator_is_singleton(): void
    {
        $a = $this->app->make(VersionNegotiator::class);
        $b = $this->app->make(VersionNegotiator::class);

        $this->assertSame($a, $b);
    }

    public function test_pipelines_are_singletons(): void
    {
        $this->assertSame(
            $this->app->make(RequestUpgradePipeline::class),
            $this->app->make(RequestUpgradePipeline::class),
        );

        $this->assertSame(
            $this->app->make(ResponseDowngradePipeline::class),
            $this->app->make(ResponseDowngradePipeline::class),
        );
    }

    public function test_manager_is_singleton(): void
    {
        $a = $this->app->make(ApiVersionistManager::class);
        $b = $this->app->make(ApiVersionistManager::class);

        $this->assertSame($a, $b);
    }

    public function test_manager_alias_resolves(): void
    {
        $manager = $this->app->make('api-versionist');

        $this->assertInstanceOf(ApiVersionistManager::class, $manager);
    }

    public function test_facade_resolves_to_manager(): void
    {
        $manager = ApiVersionist::getRegistry();

        $this->assertInstanceOf(TransformerRegistry::class, $manager);
    }

    public function test_middleware_alias_is_registered(): void
    {
        $router = $this->app->make('router');
        $middleware = $router->getMiddleware();

        $this->assertArrayHasKey('api.version', $middleware);
        $this->assertSame(ApiVersionMiddleware::class, $middleware['api.version']);
    }

    public function test_route_versioned_macro_exists(): void
    {
        $this->assertTrue(Route::hasMacro('versioned'));
    }

    public function test_request_macros_are_registered(): void
    {
        $request = $this->app->make('request');

        $this->assertTrue($request::hasMacro('apiVersion'));
        $this->assertTrue($request::hasMacro('isApiVersion'));
        $this->assertTrue($request::hasMacro('isApiVersionAtLeast'));
        $this->assertTrue($request::hasMacro('isApiVersionBefore'));
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('api-versionist.default_version'));
        $this->assertNotNull(config('api-versionist.detection_strategies'));
    }
}
