<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Tests\TestCase;

/**
 * All 4 detection strategies work end-to-end through the actual middleware.
 */
final class VersionDetectionStrategyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v2');
        $app['config']->set('api-versionist.default_version', 'v1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(\Versionist\ApiVersionist\Registry\TransformerRegistry::class);

        $registry->register($this->makeTransformer('v2',
            downgrade: function (array $data): array {
                $data['schema'] = 'v1';
                return $data;
            },
        ));

        // Route that reports the detected version
        Route::middleware('api.version')->get('/api/version-check', function (Request $request) {
            return new JsonResponse([
                'detected' => $request->attributes->get('api_version'),
            ]);
        });

        // Versioned URL route
        Route::middleware('api.version')->get('/api/v1/info', function (Request $request) {
            return new JsonResponse([
                'detected' => $request->attributes->get('api_version'),
            ]);
        });
        Route::middleware('api.version')->get('/api/v2/info', function (Request $request) {
            return new JsonResponse([
                'detected' => $request->attributes->get('api_version'),
            ]);
        });
    }

    #[Test]
    public function it_detects_version_via_url_prefix(): void
    {
        $response = $this->getJson('/api/v2/info');

        $response->assertOk();
        $response->assertJson(['detected' => 'v2']);
    }

    #[Test]
    public function it_detects_version_via_header(): void
    {
        $response = $this->getJson('/api/version-check', [
            'X-Api-Version' => 'v2',
        ]);

        $response->assertOk();
        $response->assertJson(['detected' => 'v2']);
    }

    #[Test]
    public function it_detects_version_via_accept_header(): void
    {
        $response = $this->getJson('/api/version-check', [
            'Accept' => 'application/vnd.myapi+json;version=v2',
        ]);

        $response->assertOk();
        $response->assertJson(['detected' => 'v2']);
    }

    #[Test]
    public function it_detects_version_via_query_param(): void
    {
        $response = $this->getJson('/api/version-check?version=v2');

        $response->assertOk();
        $response->assertJson(['detected' => 'v2']);
    }

    #[Test]
    public function it_falls_back_to_default_when_no_version_detected(): void
    {
        $response = $this->getJson('/api/version-check');

        $response->assertOk();
        $response->assertJson(['detected' => 'v1']);
    }

    #[Test]
    public function it_downgrades_response_for_v1_detected_via_header(): void
    {
        Route::middleware('api.version')->get('/api/data', function () {
            return new JsonResponse(['result' => 'ok']);
        });

        $response = $this->getJson('/api/data', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();
        // v2 downgrade adds 'schema' => 'v1'
        $response->assertJson(['result' => 'ok', 'schema' => 'v1']);
    }

    #[Test]
    public function it_respects_strategy_priority_order(): void
    {
        // Set only header and query_param strategies (no url_prefix)
        $this->app['config']->set('api-versionist.detection_strategies', [
            'header',
            'query_param',
        ]);

        // Rebuild the detector with new config
        $this->app->forgetInstance(\Versionist\ApiVersionist\Version\VersionDetector::class);
        $this->app->forgetInstance(\Versionist\ApiVersionist\Version\VersionNegotiator::class);
        $this->app->forgetInstance(\Versionist\ApiVersionist\Manager\ApiVersionistManager::class);

        $response = $this->getJson('/api/version-check?version=v1', [
            'X-Api-Version' => 'v2',
        ]);

        // Header should win over query param
        $response->assertJson(['detected' => 'v2']);
    }
}
