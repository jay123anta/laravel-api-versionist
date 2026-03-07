<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Versionist\ApiVersionist\ApiVersionistServiceProvider;
use Versionist\ApiVersionist\ApiVersionTransformer;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;

/**
 * Base test case for all package tests.
 *
 * Boots the package service provider via Orchestra Testbench and provides
 * a makeTransformer() helper for building anonymous transformer instances
 * inline — keeping every test fully self-contained.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Register the package service provider.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ApiVersionistServiceProvider::class,
        ];
    }

    /**
     * Set up the default package configuration for tests.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-versionist.default_version', 'v1');
        $app['config']->set('api-versionist.latest_version', 'v1');
        $app['config']->set('api-versionist.strict_mode', false);
        $app['config']->set('api-versionist.add_version_headers', true);
        $app['config']->set('api-versionist.response_data_key', null);
        $app['config']->set('api-versionist.request_data_key', null);
        $app['config']->set('api-versionist.transformers', []);
        $app['config']->set('api-versionist.deprecated_versions', []);
        $app['config']->set('api-versionist.detection_strategies', [
            'url_prefix',
            'header',
            'accept_header',
            'query_param',
        ]);
    }

    /**
     * Build an anonymous transformer for use in tests.
     *
     * @param  string                   $version     The version string (e.g. "v2").
     * @param  callable|null            $upgrade     Upgrade callback: fn(array $data): array
     * @param  callable|null            $downgrade   Downgrade callback: fn(array $data): array
     * @param  string                   $desc        Description for the transformer.
     * @param  string|null              $releasedAt  Released date string or null.
     * @return VersionTransformerInterface
     */
    protected function makeTransformer(
        string $version,
        ?callable $upgrade = null,
        ?callable $downgrade = null,
        string $desc = 'Test transformer',
        ?string $releasedAt = null,
    ): VersionTransformerInterface {
        $v = $version;
        $u = $upgrade;
        $d = $downgrade;
        $de = $desc;
        $r = $releasedAt;

        return new class($v, $u, $d, $de, $r) extends ApiVersionTransformer {
            public function __construct(
                private readonly string $v,
                private readonly ?\Closure $u,
                private readonly ?\Closure $d,
                private readonly string $de,
                private readonly ?string $r,
            ) {
            }

            public function version(): string
            {
                return $this->v;
            }

            public function description(): string
            {
                return $this->de;
            }

            public function releasedAt(): ?string
            {
                return $this->r;
            }

            public function upgradeRequest(array $data): array
            {
                return $this->u !== null ? ($this->u)($data) : $data;
            }

            public function downgradeResponse(array $data): array
            {
                return $this->d !== null ? ($this->d)($data) : $data;
            }
        };
    }
}
