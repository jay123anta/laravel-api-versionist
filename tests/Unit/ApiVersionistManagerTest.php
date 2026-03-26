<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Versionist\ApiVersionist\Events\RequestUpgraded;
use Versionist\ApiVersionist\Events\ResponseDowngraded;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Pipeline\RequestUpgradePipeline;
use Versionist\ApiVersionist\Pipeline\ResponseDowngradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Tests\TestCase;
use Versionist\ApiVersionist\Version\VersionDetector;
use Versionist\ApiVersionist\Version\VersionNegotiator;

class ApiVersionistManagerTest extends TestCase
{
    private function buildManager(array $transformers = [], array $configOverrides = []): ApiVersionistManager
    {
        $registry = new TransformerRegistry();
        if ($transformers !== []) {
            $registry->registerMany($transformers);
        }

        $config = array_merge([
            'default_version' => 'v1',
            'latest_version' => 'v2',
            'strict_mode' => false,
            'add_version_headers' => true,
            'deprecated_versions' => [],
            'response_data_key' => null,
            'request_data_key' => null,
            'detection_strategies' => ['header'],
            'header_name' => 'X-Api-Version',
            'changelog' => ['enabled' => false, 'endpoint' => '/api/versions'],
        ], $configOverrides);

        $detector = new VersionDetector($config);
        $negotiator = new VersionNegotiator($detector, $registry, $config);

        return new ApiVersionistManager(
            $negotiator,
            new RequestUpgradePipeline($registry),
            new ResponseDowngradePipeline($registry),
            $registry,
            $this->app->make('events'),
            $config,
        );
    }

    public function test_upgrade_request_transforms_data(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            fn (array $data) => array_merge($data, ['handle' => $data['username'] ?? null]),
        );

        $manager = $this->buildManager([$transformer]);

        $request = Request::create('/api/users', 'POST', ['username' => 'jane']);
        $upgraded = $manager->upgradeRequest($request, 'v1');

        $this->assertSame('jane', $upgraded->input('handle'));
    }

    public function test_upgrade_request_skips_when_already_latest(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            fn (array $data) => array_merge($data, ['touched' => true]),
        );

        $manager = $this->buildManager([$transformer]);

        $request = Request::create('/api/users', 'POST', ['name' => 'jane']);
        $upgraded = $manager->upgradeRequest($request, 'v2');

        $this->assertNull($upgraded->input('touched'));
    }

    public function test_upgrade_request_dispatches_event(): void
    {
        Event::fake([RequestUpgraded::class]);

        $transformer = $this->makeTransformer(
            'v2',
            fn (array $data) => $data,
        );

        $manager = $this->buildManager([$transformer]);

        $request = Request::create('/api/users', 'POST', ['name' => 'jane']);
        $manager->upgradeRequest($request, 'v1');

        Event::assertDispatched(RequestUpgraded::class, function (RequestUpgraded $event) {
            return $event->fromVersion === 'v1' && $event->toVersion === 'v2';
        });
    }

    public function test_downgrade_response_transforms_data(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            null,
            fn (array $data) => array_merge($data, ['username' => $data['handle'] ?? null]),
        );

        $manager = $this->buildManager([$transformer]);

        $response = new JsonResponse(['handle' => 'jane']);
        $downgraded = $manager->downgradeResponse($response, 'v1');

        $data = $downgraded->getData(true);
        $this->assertSame('jane', $data['username']);
    }

    public function test_downgrade_response_skips_when_already_latest(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            null,
            fn (array $data) => array_merge($data, ['touched' => true]),
        );

        $manager = $this->buildManager([$transformer]);

        $response = new JsonResponse(['name' => 'jane']);
        $downgraded = $manager->downgradeResponse($response, 'v2');

        $data = $downgraded->getData(true);
        $this->assertArrayNotHasKey('touched', $data);
    }

    public function test_downgrade_response_dispatches_event(): void
    {
        Event::fake([ResponseDowngraded::class]);

        $transformer = $this->makeTransformer(
            'v2',
            null,
            fn (array $data) => $data,
        );

        $manager = $this->buildManager([$transformer]);

        $response = new JsonResponse(['name' => 'jane']);
        $manager->downgradeResponse($response, 'v1');

        Event::assertDispatched(ResponseDowngraded::class, function (ResponseDowngraded $event) {
            return $event->fromVersion === 'v2' && $event->toVersion === 'v1';
        });
    }

    public function test_downgrade_response_adds_version_headers(): void
    {
        $transformer = $this->makeTransformer('v2');
        $manager = $this->buildManager([$transformer]);

        $response = new JsonResponse(['name' => 'jane']);
        $downgraded = $manager->downgradeResponse($response, 'v1');

        $this->assertSame('v1', $downgraded->headers->get('X-Api-Version'));
        $this->assertSame('v2', $downgraded->headers->get('X-Api-Latest-Version'));
    }

    public function test_downgrade_response_skips_headers_when_disabled(): void
    {
        $transformer = $this->makeTransformer('v2');
        $manager = $this->buildManager([$transformer], ['add_version_headers' => false]);

        $response = new JsonResponse(['name' => 'jane']);
        $downgraded = $manager->downgradeResponse($response, 'v2');

        $this->assertNull($downgraded->headers->get('X-Api-Version'));
    }

    public function test_envelope_mode_upgrade_only_transforms_data_key(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            fn (array $data) => array_merge($data, ['upgraded' => true]),
        );

        $manager = $this->buildManager([$transformer], ['request_data_key' => 'data']);

        $request = Request::create('/api/users', 'POST', [
            'data' => ['name' => 'jane'],
            'meta' => ['page' => 1],
        ]);

        $upgraded = $manager->upgradeRequest($request, 'v1');

        $this->assertTrue($upgraded->input('data.upgraded'));
        $this->assertSame(1, $upgraded->input('meta.page'));
    }

    public function test_envelope_mode_downgrade_only_transforms_data_key(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            null,
            fn (array $data) => array_merge($data, ['downgraded' => true]),
        );

        $manager = $this->buildManager([$transformer], ['response_data_key' => 'data']);

        $response = new JsonResponse([
            'data' => ['name' => 'jane'],
            'meta' => ['page' => 1],
        ]);

        $downgraded = $manager->downgradeResponse($response, 'v1');
        $payload = $downgraded->getData(true);

        $this->assertTrue($payload['data']['downgraded']);
        $this->assertSame(1, $payload['meta']['page']);
    }

    public function test_non_array_data_key_is_handled_gracefully(): void
    {
        $transformer = $this->makeTransformer(
            'v2',
            fn (array $data) => $data,
        );

        $manager = $this->buildManager([$transformer], ['request_data_key' => 'data']);

        $request = Request::create('/api/users', 'POST', [
            'data' => 'not-an-array',
        ]);

        $upgraded = $manager->upgradeRequest($request, 'v1');

        $this->assertIsArray($upgraded->all());
    }

    public function test_negotiate_delegates_to_negotiator(): void
    {
        $transformer = $this->makeTransformer('v2');
        $manager = $this->buildManager([$transformer]);

        $request = Request::create('/api/users');
        $request->headers->set('X-Api-Version', 'v2');

        $this->assertSame('v2', $manager->negotiate($request));
    }

    public function test_get_registry_returns_registry(): void
    {
        $transformer = $this->makeTransformer('v2');
        $manager = $this->buildManager([$transformer]);

        $registry = $manager->getRegistry();
        $this->assertInstanceOf(TransformerRegistry::class, $registry);
        $this->assertCount(1, $registry->all());
    }
}
