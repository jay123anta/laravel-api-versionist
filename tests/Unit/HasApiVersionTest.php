<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Tests\TestCase;

class HasApiVersionTest extends TestCase
{
    public function test_api_version_returns_set_version(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v2');

        $this->assertSame('v2', $request->apiVersion());
    }

    public function test_api_version_returns_fallback_when_not_set(): void
    {
        $request = Request::create('/api/users');

        $this->assertSame('v1', $request->apiVersion());
    }

    public function test_api_version_returns_custom_fallback(): void
    {
        $request = Request::create('/api/users');

        $this->assertSame('v2', $request->apiVersion('v2'));
    }

    public function test_is_api_version_returns_true_for_match(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v2');

        $this->assertTrue($request->isApiVersion('v2'));
    }

    public function test_is_api_version_returns_false_for_mismatch(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v2');

        $this->assertFalse($request->isApiVersion('v3'));
    }

    public function test_is_api_version_returns_false_when_not_set(): void
    {
        $request = Request::create('/api/users');

        $this->assertFalse($request->isApiVersion('v1'));
    }

    public function test_is_api_version_at_least_returns_true_for_equal(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v2');

        $this->assertTrue($request->isApiVersionAtLeast('v2'));
    }

    public function test_is_api_version_at_least_returns_true_for_higher(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v3');

        $this->assertTrue($request->isApiVersionAtLeast('v2'));
    }

    public function test_is_api_version_at_least_returns_false_for_lower(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v1');

        $this->assertFalse($request->isApiVersionAtLeast('v2'));
    }

    public function test_is_api_version_at_least_returns_false_when_not_set(): void
    {
        $request = Request::create('/api/users');

        $this->assertFalse($request->isApiVersionAtLeast('v1'));
    }

    public function test_is_api_version_before_returns_true_for_lower(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v1');

        $this->assertTrue($request->isApiVersionBefore('v2'));
    }

    public function test_is_api_version_before_returns_false_for_equal(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v2');

        $this->assertFalse($request->isApiVersionBefore('v2'));
    }

    public function test_is_api_version_before_returns_false_for_higher(): void
    {
        $request = Request::create('/api/users');
        $request->attributes->set('api_version', 'v3');

        $this->assertFalse($request->isApiVersionBefore('v2'));
    }

    public function test_is_api_version_before_returns_false_when_not_set(): void
    {
        $request = Request::create('/api/users');

        $this->assertFalse($request->isApiVersionBefore('v2'));
    }
}
