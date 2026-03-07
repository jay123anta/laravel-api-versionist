<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Tests\TestCase;

/**
 * Deprecated versions get Deprecation + Sunset + Link headers;
 * non-deprecated versions do not.
 */
final class DeprecationHeaderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v3');
        $app['config']->set('api-versionist.default_version', 'v1');
        $app['config']->set('api-versionist.add_version_headers', true);
        $app['config']->set('api-versionist.deprecated_versions', [
            'v1' => '2025-06-01',
            'v2' => null,
        ]);
        $app['config']->set('api-versionist.changelog', [
            'enabled' => true,
            'endpoint' => '/api/versions',
            'middleware' => ['api'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(\Versionist\ApiVersionist\Registry\TransformerRegistry::class);

        $registry->register($this->makeTransformer('v2'));
        $registry->register($this->makeTransformer('v3'));

        Route::middleware('api.version')->get('/api/test', function () {
            return new JsonResponse(['ok' => true]);
        });
    }

    #[Test]
    public function it_adds_deprecation_headers_for_deprecated_version_with_sunset(): void
    {
        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $response->assertHeader('Sunset', '2025-06-01');
        $response->assertHeader('X-Api-Version', 'v1');
        $response->assertHeader('X-Api-Latest-Version', 'v3');
    }

    #[Test]
    public function it_adds_link_header_when_changelog_is_enabled(): void
    {
        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertHeader('Link', '</api/versions>; rel="successor-version"');
    }

    #[Test]
    public function it_adds_deprecation_header_without_sunset_when_date_is_null(): void
    {
        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v2',
        ]);

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $response->assertHeaderMissing('Sunset');
    }

    #[Test]
    public function it_does_not_add_deprecation_headers_for_non_deprecated_version(): void
    {
        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v3',
        ]);

        $response->assertOk();
        $response->assertHeaderMissing('Deprecation');
        $response->assertHeaderMissing('Sunset');
        $response->assertHeaderMissing('Link');
    }

    #[Test]
    public function it_always_adds_version_identification_headers(): void
    {
        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v3',
        ]);

        $response->assertHeader('X-Api-Version', 'v3');
        $response->assertHeader('X-Api-Latest-Version', 'v3');
    }

    #[Test]
    public function it_does_not_add_headers_when_disabled(): void
    {
        $this->app['config']->set('api-versionist.add_version_headers', false);

        // Rebuild manager with new config
        $this->app->forgetInstance(\Versionist\ApiVersionist\Manager\ApiVersionistManager::class);

        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();
        $response->assertHeaderMissing('X-Api-Version');
        $response->assertHeaderMissing('Deprecation');
    }

    #[Test]
    public function it_does_not_add_link_header_when_changelog_is_disabled(): void
    {
        $this->app['config']->set('api-versionist.changelog.enabled', false);

        // Rebuild manager with new config
        $this->app->forgetInstance(\Versionist\ApiVersionist\Manager\ApiVersionistManager::class);

        $response = $this->getJson('/api/test', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $response->assertHeaderMissing('Link');
    }
}
