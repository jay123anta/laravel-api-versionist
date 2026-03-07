<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Tests\TestCase;

/**
 * 3-version chain data integrity: v1→v2→v3 upgrade and v3→v2→v1 downgrade
 * preserves all field values through the full round-trip.
 */
final class MultiVersionTransformTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v3');
        $app['config']->set('api-versionist.default_version', 'v1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(\Versionist\ApiVersionist\Registry\TransformerRegistry::class);

        // V2: name → full_name, add 'age' default
        $registry->register($this->makeTransformer('v2',
            upgrade: function (array $data): array {
                if (isset($data['name'])) {
                    $data['full_name'] = $data['name'];
                    unset($data['name']);
                }
                if (!isset($data['age'])) {
                    $data['age'] = 0;
                }
                return $data;
            },
            downgrade: function (array $data): array {
                if (isset($data['full_name'])) {
                    $data['name'] = $data['full_name'];
                    unset($data['full_name']);
                }
                unset($data['age']);
                return $data;
            },
        ));

        // V3: email → contact.email, add 'active' flag
        $registry->register($this->makeTransformer('v3',
            upgrade: function (array $data): array {
                if (isset($data['email'])) {
                    $data['contact'] = ['email' => $data['email']];
                    unset($data['email']);
                }
                if (!isset($data['active'])) {
                    $data['active'] = true;
                }
                return $data;
            },
            downgrade: function (array $data): array {
                if (isset($data['contact']['email'])) {
                    $data['email'] = $data['contact']['email'];
                    unset($data['contact']);
                }
                unset($data['active']);
                return $data;
            },
        ));

        // Route that echoes back request input (already upgraded to v3)
        Route::middleware('api.version')->post('/api/echo', function (Request $request) {
            return new JsonResponse($request->all());
        });

        // Route that returns a v3-schema response
        Route::middleware('api.version')->get('/api/user', function () {
            return new JsonResponse([
                'full_name' => 'Bob',
                'age' => 25,
                'contact' => ['email' => 'bob@example.com'],
                'active' => true,
            ]);
        });
    }

    #[Test]
    public function it_upgrades_v1_request_through_full_chain(): void
    {
        $response = $this->postJson('/api/echo', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
        ], ['X-Api-Version' => 'v1']);

        $response->assertOk();

        $data = $response->json();

        // After full upgrade v1→v2→v3 and then downgrade v3→v2→v1:
        // The echo returns the upgraded data, which then gets downgraded.
        $this->assertArrayHasKey('name', $data);
        $this->assertSame('Bob', $data['name']);
        $this->assertSame('bob@example.com', $data['email']);
    }

    #[Test]
    public function it_downgrades_v3_response_through_full_chain(): void
    {
        $response = $this->getJson('/api/user', [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $data = $response->json();

        // v3 → v2: contact.email → email, remove 'active'
        // v2 → v1: full_name → name, remove 'age'
        $this->assertSame('Bob', $data['name']);
        $this->assertSame('bob@example.com', $data['email']);
        $this->assertArrayNotHasKey('full_name', $data);
        $this->assertArrayNotHasKey('contact', $data);
        $this->assertArrayNotHasKey('age', $data);
        $this->assertArrayNotHasKey('active', $data);
    }

    #[Test]
    public function it_preserves_values_through_v2_partial_downgrade(): void
    {
        $response = $this->getJson('/api/user', [
            'X-Api-Version' => 'v2',
        ]);

        $response->assertOk();

        $data = $response->json();

        // v3 → v2 only: contact.email → email, remove 'active'
        // full_name and age remain
        $this->assertSame('Bob', $data['full_name']);
        $this->assertSame(25, $data['age']);
        $this->assertSame('bob@example.com', $data['email']);
        $this->assertArrayNotHasKey('contact', $data);
        $this->assertArrayNotHasKey('active', $data);
    }

    #[Test]
    public function it_preserves_all_fields_at_latest_version(): void
    {
        $response = $this->getJson('/api/user', [
            'X-Api-Version' => 'v3',
        ]);

        $response->assertOk();

        $data = $response->json();

        // No transformation
        $this->assertSame('Bob', $data['full_name']);
        $this->assertSame(25, $data['age']);
        $this->assertSame('bob@example.com', $data['contact']['email']);
        $this->assertTrue($data['active']);
    }

    #[Test]
    public function it_round_trips_v1_data_through_upgrade_and_downgrade(): void
    {
        // Send v1 data, controller echoes it (in v3 format), response is downgraded to v1
        $v1Input = ['name' => 'Charlie', 'email' => 'charlie@test.com'];

        $response = $this->postJson('/api/echo', $v1Input, [
            'X-Api-Version' => 'v1',
        ]);

        $response->assertOk();

        $data = $response->json();

        // Round-trip should preserve the original v1 field values
        $this->assertSame('Charlie', $data['name']);
        $this->assertSame('charlie@test.com', $data['email']);
    }

    #[Test]
    public function it_upgrades_v2_request_with_single_step(): void
    {
        // V2 client only needs v3 upgrade (not v2)
        $response = $this->postJson('/api/echo', [
            'full_name' => 'Dave',
            'age' => 30,
            'email' => 'dave@test.com',
        ], ['X-Api-Version' => 'v2']);

        $response->assertOk();

        $data = $response->json();

        // Downgraded from v3 to v2: full_name stays, email comes back from contact
        $this->assertSame('Dave', $data['full_name']);
        $this->assertSame(30, $data['age']);
        $this->assertSame('dave@test.com', $data['email']);
    }
}
