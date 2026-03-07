<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Tests\TestCase;

/**
 * End-to-end middleware tests: version negotiation, request upgrade,
 * response downgrade, header injection, and non-JSON passthrough.
 */
final class MiddlewarePipelineTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v3');
        $app['config']->set('api-versionist.default_version', 'v1');
        $app['config']->set('api-versionist.add_version_headers', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerTransformers();
        $this->registerRoutes();
    }

    private function registerTransformers(): void
    {
        $registry = $this->app->make(\Versionist\ApiVersionist\Registry\TransformerRegistry::class);

        // V2: name → full_name
        $registry->register($this->makeTransformer('v2',
            upgrade: function (array $data): array {
                if (isset($data['name'])) {
                    $data['full_name'] = $data['name'];
                    unset($data['name']);
                }
                return $data;
            },
            downgrade: function (array $data): array {
                if (isset($data['full_name'])) {
                    $data['name'] = $data['full_name'];
                    unset($data['full_name']);
                }
                return $data;
            },
        ));

        // V3: email → contact.email
        $registry->register($this->makeTransformer('v3',
            upgrade: function (array $data): array {
                if (isset($data['email'])) {
                    $data['contact'] = ['email' => $data['email']];
                    unset($data['email']);
                }
                return $data;
            },
            downgrade: function (array $data): array {
                if (isset($data['contact']['email'])) {
                    $data['email'] = $data['contact']['email'];
                    unset($data['contact']);
                }
                return $data;
            },
        ));
    }

    private function registerRoutes(): void
    {
        // JSON endpoint that echoes back request input
        Route::middleware('api.version')->post('/api/users', function (Request $request) {
            return new JsonResponse($request->all());
        });

        // JSON endpoint that returns controller data (v3 schema)
        Route::middleware('api.version')->get('/api/users/{id}', function () {
            return new JsonResponse([
                'full_name' => 'Alice',
                'contact' => ['email' => 'alice@test.com'],
            ]);
        });

        // Non-JSON endpoint
        Route::middleware('api.version')->get('/api/download', function () {
            return response('file-contents', 200, ['Content-Type' => 'text/plain']);
        });
    }

    #[Test]
    public function it_upgrades_v1_request_to_latest_schema(): void
    {
        $response = $this->postJson('/api/users', [
            'name' => 'Alice',
            'email' => 'alice@test.com',
        ], [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        // Controller received v3 schema (upgraded from v1), then response
        // was downgraded back to v1 for the client.
        $data = $response->json();
        $this->assertSame('Alice', $data['name']);
        $this->assertSame('alice@test.com', $data['email']);
        $this->assertArrayNotHasKey('full_name', $data);
        $this->assertArrayNotHasKey('contact', $data);
    }

    #[Test]
    public function it_downgrades_v3_response_to_v1_schema(): void
    {
        $response = $this->getJson('/api/users/1', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $data = $response->json();
        $this->assertSame('Alice', $data['name']);
        $this->assertSame('alice@test.com', $data['email']);
        $this->assertArrayNotHasKey('full_name', $data);
        $this->assertArrayNotHasKey('contact', $data);
    }

    #[Test]
    public function it_adds_version_headers_to_response(): void
    {
        $response = $this->getJson('/api/users/1', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertHeader('X-Api-Version', 'v1');
        $response->assertHeader('X-Api-Latest-Version', 'v3');
    }

    #[Test]
    public function it_sets_api_version_attribute_on_request(): void
    {
        Route::middleware('api.version')->get('/api/check-version', function (Request $request) {
            return new JsonResponse([
                'version' => $request->attributes->get('api_version'),
            ]);
        });

        $response = $this->getJson('/api/check-version', [
            'X-Api-Version' => 'v2',
        ]);

        $response->assertOk();
        $response->assertJson(['version' => 'v2']);
    }

    #[Test]
    public function it_passes_non_json_response_through_untouched(): void
    {
        $response = $this->get('/api/download', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();
        $this->assertSame('file-contents', $response->getContent());
    }

    #[Test]
    public function it_does_not_transform_when_client_is_at_latest_version(): void
    {
        $response = $this->getJson('/api/users/1', [
            'X-Api-Version' => 'v3',
        ]);

        $response->assertOk();

        // No downgrade — should see v3 schema
        $data = $response->json();
        $this->assertSame('Alice', $data['full_name']);
        $this->assertSame('alice@test.com', $data['contact']['email']);
    }

    #[Test]
    public function it_uses_default_version_when_no_version_detected(): void
    {
        // No version header or query param — defaults to v1
        $response = $this->getJson('/api/users/1');

        $response->assertOk();

        // Should downgrade from v3 to v1
        $data = $response->json();
        $this->assertSame('Alice', $data['name']);
        $this->assertSame('alice@test.com', $data['email']);
    }

    #[Test]
    public function it_partially_downgrades_for_v2_client(): void
    {
        $response = $this->getJson('/api/users/1', [
            'X-Api-Version' => 'v2',
        ]);

        $response->assertOk();

        // V2 schema: full_name stays, but contact.email → email
        $data = $response->json();
        $this->assertSame('Alice', $data['full_name']);
        $this->assertSame('alice@test.com', $data['email']);
        $this->assertArrayNotHasKey('contact', $data);
        $this->assertArrayNotHasKey('name', $data);
    }
}
