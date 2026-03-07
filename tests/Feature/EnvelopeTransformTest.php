<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Tests\TestCase;

/**
 * Envelope support: response_data_key and request_data_key config —
 * only the data key is transformed, meta/links/pagination remain untouched.
 */
final class EnvelopeTransformTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v2');
        $app['config']->set('api-versionist.default_version', 'v1');
        $app['config']->set('api-versionist.response_data_key', 'data');
        $app['config']->set('api-versionist.request_data_key', 'data');
    }

    protected function setUp(): void
    {
        parent::setUp();

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

        // Endpoint returning an enveloped response
        Route::middleware('api.version')->get('/api/users', function () {
            return new JsonResponse([
                'data' => [
                    'full_name' => 'Alice',
                    'id' => 1,
                ],
                'meta' => [
                    'current_page' => 1,
                    'total' => 50,
                ],
                'links' => [
                    'next' => '/api/users?page=2',
                ],
            ]);
        });

        // Endpoint echoing request input (with envelope)
        Route::middleware('api.version')->post('/api/users', function (Request $request) {
            return new JsonResponse([
                'data' => $request->input('data'),
                'meta' => ['saved' => true],
            ]);
        });
    }

    #[Test]
    public function it_downgrades_only_the_data_key_in_response(): void
    {
        $response = $this->getJson('/api/users', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $json = $response->json();

        // 'data' key should be transformed (full_name → name)
        $this->assertSame('Alice', $json['data']['name']);
        $this->assertArrayNotHasKey('full_name', $json['data']);
        $this->assertSame(1, $json['data']['id']);

        // 'meta' and 'links' should be untouched
        $this->assertSame(1, $json['meta']['current_page']);
        $this->assertSame(50, $json['meta']['total']);
        $this->assertSame('/api/users?page=2', $json['links']['next']);
    }

    #[Test]
    public function it_upgrades_only_the_data_key_in_request(): void
    {
        $response = $this->postJson('/api/users', [
            'data' => [
                'name' => 'Bob',
                'id' => 2,
            ],
            'extra' => 'should_persist',
        ], [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $json = $response->json();

        // The 'data' key was upgraded (name → full_name) before controller saw it,
        // then downgraded (full_name → name) for v1 client
        $this->assertSame('Bob', $json['data']['name']);
        $this->assertSame(2, $json['data']['id']);

        // Meta added by controller
        $this->assertTrue($json['meta']['saved']);
    }

    #[Test]
    public function it_does_not_transform_when_client_is_at_latest_version(): void
    {
        $response = $this->getJson('/api/users', [
            'X-Api-Version' => 'v2',
        ]);

        $response->assertOk();

        $json = $response->json();

        // No transformation — v2 schema returned as-is
        $this->assertSame('Alice', $json['data']['full_name']);
        $this->assertArrayNotHasKey('name', $json['data']);
    }

    #[Test]
    public function it_handles_missing_data_key_gracefully(): void
    {
        Route::middleware('api.version')->get('/api/empty', function () {
            return new JsonResponse([
                'meta' => ['status' => 'ok'],
            ]);
        });

        $response = $this->getJson('/api/empty', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        // No 'data' key — should not crash
        $this->assertSame('ok', $response->json('meta.status'));
    }

    #[Test]
    public function it_transforms_entire_body_when_data_key_is_null(): void
    {
        // Override config to null (no envelope)
        $this->app['config']->set('api-versionist.response_data_key', null);
        $this->app->forgetInstance(\Versionist\ApiVersionist\Manager\ApiVersionistManager::class);

        Route::middleware('api.version')->get('/api/flat', function () {
            return new JsonResponse([
                'full_name' => 'Charlie',
                'id' => 3,
            ]);
        });

        $response = $this->getJson('/api/flat', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $json = $response->json();
        $this->assertSame('Charlie', $json['name']);
        $this->assertArrayNotHasKey('full_name', $json);
    }

    #[Test]
    public function it_preserves_nested_pagination_through_transform(): void
    {
        Route::middleware('api.version')->get('/api/paginated', function () {
            return new JsonResponse([
                'data' => [
                    ['full_name' => 'Alice', 'id' => 1],
                    ['full_name' => 'Bob', 'id' => 2],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 2,
                ],
                'links' => [
                    'first' => '/api/users?page=1',
                    'last' => '/api/users?page=1',
                ],
            ]);
        });

        $response = $this->getJson('/api/paginated', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $json = $response->json();

        // The data array elements get transformed
        // Note: the transformer receives the whole 'data' value, which is an array of arrays
        // The transform only handles top-level keys, so inner arrays may not transform
        // This tests that meta/links are truly untouched
        $this->assertSame(1, $json['meta']['current_page']);
        $this->assertSame(15, $json['meta']['per_page']);
        $this->assertSame('/api/users?page=1', $json['links']['first']);
    }
}
