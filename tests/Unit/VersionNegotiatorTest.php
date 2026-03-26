<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Exceptions\UnknownVersionException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Tests\TestCase;
use Versionist\ApiVersionist\Version\VersionDetector;
use Versionist\ApiVersionist\Version\VersionNegotiator;

class VersionNegotiatorTest extends TestCase
{
    private TransformerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new TransformerRegistry();
        $this->registry->registerMany([
            $this->makeTransformer('v2'),
            $this->makeTransformer('v3'),
        ]);
    }

    private function buildNegotiator(array $configOverrides = []): VersionNegotiator
    {
        $config = array_merge([
            'default_version' => 'v1',
            'latest_version' => 'v3',
            'strict_mode' => false,
            'deprecated_versions' => [],
            'detection_strategies' => ['header'],
            'header_name' => 'X-Api-Version',
        ], $configOverrides);

        return new VersionNegotiator(
            new VersionDetector($config),
            $this->registry,
            $config,
        );
    }

    public function test_negotiate_returns_detected_version(): void
    {
        $negotiator = $this->buildNegotiator();
        $request = Request::create('/api/users');
        $request->headers->set('X-Api-Version', 'v2');

        $this->assertSame('v2', $negotiator->negotiate($request));
    }

    public function test_negotiate_returns_default_when_no_version_detected(): void
    {
        $negotiator = $this->buildNegotiator();
        $request = Request::create('/api/users');

        $this->assertSame('v1', $negotiator->negotiate($request));
    }

    public function test_negotiate_falls_back_to_default_for_unknown_version_in_non_strict_mode(): void
    {
        $negotiator = $this->buildNegotiator(['strict_mode' => false]);
        $request = Request::create('/api/users');
        $request->headers->set('X-Api-Version', 'v99');

        $this->assertSame('v1', $negotiator->negotiate($request));
    }

    public function test_negotiate_throws_for_unknown_version_in_strict_mode(): void
    {
        $negotiator = $this->buildNegotiator(['strict_mode' => true]);
        $request = Request::create('/api/users');
        $request->headers->set('X-Api-Version', 'v99');

        $this->expectException(UnknownVersionException::class);
        $negotiator->negotiate($request);
    }

    public function test_is_deprecated_returns_true_for_deprecated_version(): void
    {
        $negotiator = $this->buildNegotiator([
            'deprecated_versions' => ['v1' => '2025-12-31'],
        ]);

        $this->assertTrue($negotiator->isDeprecated('v1'));
    }

    public function test_is_deprecated_returns_false_for_active_version(): void
    {
        $negotiator = $this->buildNegotiator();

        $this->assertFalse($negotiator->isDeprecated('v2'));
    }

    public function test_get_sunset_date_returns_date(): void
    {
        $negotiator = $this->buildNegotiator([
            'deprecated_versions' => ['v1' => '2025-12-31'],
        ]);

        $this->assertSame('2025-12-31', $negotiator->getSunsetDate('v1'));
    }

    public function test_get_sunset_date_returns_null_when_no_date_set(): void
    {
        $negotiator = $this->buildNegotiator([
            'deprecated_versions' => ['v1' => null],
        ]);

        $this->assertNull($negotiator->getSunsetDate('v1'));
    }

    public function test_get_sunset_date_returns_null_for_non_deprecated_version(): void
    {
        $negotiator = $this->buildNegotiator();

        $this->assertNull($negotiator->getSunsetDate('v2'));
    }

    public function test_build_deprecation_headers_includes_version_headers(): void
    {
        $negotiator = $this->buildNegotiator();
        $headers = $negotiator->buildDeprecationHeaders('v2', 'v3');

        $this->assertSame('v2', $headers['X-Api-Version']);
        $this->assertSame('v3', $headers['X-Api-Latest-Version']);
        $this->assertArrayNotHasKey('Deprecation', $headers);
    }

    public function test_build_deprecation_headers_includes_rfc8594_for_deprecated(): void
    {
        $negotiator = $this->buildNegotiator([
            'deprecated_versions' => ['v1' => '2025-06-01'],
        ]);

        $headers = $negotiator->buildDeprecationHeaders('v1', 'v3');

        $this->assertSame('true', $headers['Deprecation']);
        $this->assertSame('2025-06-01', $headers['Sunset']);
    }

    public function test_build_deprecation_headers_omits_sunset_when_null(): void
    {
        $negotiator = $this->buildNegotiator([
            'deprecated_versions' => ['v1' => null],
        ]);

        $headers = $negotiator->buildDeprecationHeaders('v1', 'v3');

        $this->assertSame('true', $headers['Deprecation']);
        $this->assertArrayNotHasKey('Sunset', $headers);
    }
}
