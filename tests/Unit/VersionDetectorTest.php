<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Versionist\ApiVersionist\Version\VersionDetector;

final class VersionDetectorTest extends TestCase
{
    /**
     * Build a VersionDetector with the given config merged into defaults.
     */
    private function makeDetector(array $config = []): VersionDetector
    {
        return new VersionDetector(array_merge([
            'detection_strategies' => ['url_prefix', 'header', 'accept_header', 'query_param'],
            'header_name' => 'X-Api-Version',
            'accept_header_pattern' => '/application\/vnd\.[a-zA-Z0-9.-]+\+json;\s*version=(?P<version>[vV]?\d+(?:\.\d+)?)/',
            'query_param' => 'version',
            'url_prefix_pattern' => '/\/(?:api\/)?(?P<version>[vV]\d+(?:\.\d+)?)(?:\/|$)/',
        ], $config));
    }

    // ──────────────────────────────────────────────────────────
    //  URL Prefix Strategy
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_detects_version_from_url_prefix(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['url_prefix'],
        ]);

        $request = Request::create('/api/v2/users', 'GET');
        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_detects_version_from_url_prefix_without_api_segment(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['url_prefix'],
        ]);

        $request = Request::create('/v3/users', 'GET');
        $this->assertSame('v3', $detector->detect($request));
    }

    #[Test]
    public function it_returns_null_when_url_has_no_version(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['url_prefix'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $this->assertNull($detector->detect($request));
    }

    // ──────────────────────────────────────────────────────────
    //  Header Strategy
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_detects_version_from_custom_header(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Api-Version', 'v2');

        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_returns_null_when_header_is_absent(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $this->assertNull($detector->detect($request));
    }

    #[Test]
    public function it_uses_configured_header_name(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header'],
            'header_name' => 'Api-Version',
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('Api-Version', 'v4');

        $this->assertSame('v4', $detector->detect($request));
    }

    // ──────────────────────────────────────────────────────────
    //  Accept Header Strategy
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_detects_version_from_accept_header(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['accept_header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('Accept', 'application/vnd.myapi+json;version=2');

        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_detects_version_from_accept_header_with_v_prefix(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['accept_header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('Accept', 'application/vnd.myapi+json;version=v3');

        $this->assertSame('v3', $detector->detect($request));
    }

    #[Test]
    public function it_returns_null_when_accept_header_has_no_version(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['accept_header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->assertNull($detector->detect($request));
    }

    // ──────────────────────────────────────────────────────────
    //  Query Param Strategy
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_detects_version_from_query_param(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['query_param'],
        ]);

        $request = Request::create('/api/users?version=v2', 'GET');
        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_returns_null_when_query_param_is_absent(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['query_param'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $this->assertNull($detector->detect($request));
    }

    #[Test]
    public function it_uses_configured_query_param_name(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['query_param'],
            'query_param' => 'api_version',
        ]);

        $request = Request::create('/api/users?api_version=v5', 'GET');
        $this->assertSame('v5', $detector->detect($request));
    }

    // ──────────────────────────────────────────────────────────
    //  Strategy priority
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_uses_first_matching_strategy(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header', 'query_param'],
        ]);

        $request = Request::create('/api/users?version=v3', 'GET');
        $request->headers->set('X-Api-Version', 'v2');

        // Header is first → should win
        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_falls_through_to_next_strategy_on_failure(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header', 'query_param'],
        ]);

        // No header set, only query param
        $request = Request::create('/api/users?version=v4', 'GET');

        $this->assertSame('v4', $detector->detect($request));
    }

    #[Test]
    public function it_returns_null_when_all_strategies_fail(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header', 'query_param'],
        ]);

        $request = Request::create('/api/users', 'GET');

        $this->assertNull($detector->detect($request));
    }

    #[Test]
    public function it_skips_invalid_version_strings(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header', 'query_param'],
        ]);

        $request = Request::create('/api/users?version=v2', 'GET');
        $request->headers->set('X-Api-Version', 'not-a-version');

        // Invalid header value should be skipped, query param wins
        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_ignores_unknown_strategy_names(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['nonexistent', 'header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Api-Version', 'v2');

        $this->assertSame('v2', $detector->detect($request));
    }

    // ──────────────────────────────────────────────────────────
    //  Sanitization
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_strips_control_characters_from_version(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Api-Version', "v2\x00\x0a");

        $this->assertSame('v2', $detector->detect($request));
    }

    #[Test]
    public function it_rejects_oversized_version_strings(): void
    {
        $detector = $this->makeDetector([
            'detection_strategies' => ['header'],
        ]);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Api-Version', str_repeat('v1', 100));

        // After truncation to 32 chars, this won't be a valid version
        $this->assertNull($detector->detect($request));
    }
}
