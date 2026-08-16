<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Tests\TestCase;

/**
 * The opt-in changelog endpoint serves the machine-readable version
 * changelog advertised by the Link: rel="successor-version" header.
 */
final class ChangelogEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v2');
        $app['config']->set('api-versionist.deprecated_versions', [
            'v1' => '2025-06-01',
        ]);
        $app['config']->set('api-versionist.changelog', [
            'enabled'    => true,
            'endpoint'   => '/api/versions',
            'middleware' => [],
        ]);
    }

    #[Test]
    public function it_serves_the_version_changelog_as_json(): void
    {
        $registry = $this->app->make(\Versionist\ApiVersionist\Registry\TransformerRegistry::class);
        $registry->register($this->makeTransformer('v2', desc: 'Rename name to full_name', releasedAt: '2025-01-01'));

        $response = $this->getJson('/api/versions');

        $response->assertOk();
        $response->assertJson([
            'baseline' => 'v1',
            'latest'   => 'v2',
        ]);

        $versions = $response->json('versions');

        $this->assertCount(2, $versions);

        $this->assertSame('v1', $versions[0]['version']);
        $this->assertTrue($versions[0]['is_baseline']);
        $this->assertTrue($versions[0]['deprecated']);
        $this->assertSame('2025-06-01', $versions[0]['sunset_date']);

        $this->assertSame('v2', $versions[1]['version']);
        $this->assertTrue($versions[1]['is_latest']);
        $this->assertSame('Rename name to full_name', $versions[1]['description']);
        $this->assertSame('2025-01-01', $versions[1]['released_at']);
        $this->assertFalse($versions[1]['deprecated']);
    }

    #[Test]
    public function it_does_not_expose_transformer_class_names(): void
    {
        $registry = $this->app->make(\Versionist\ApiVersionist\Registry\TransformerRegistry::class);
        $registry->register($this->makeTransformer('v2'));

        $versions = $this->getJson('/api/versions')->json('versions');

        foreach ($versions as $entry) {
            $this->assertArrayNotHasKey('transformer_class', $entry);
        }
    }

    #[Test]
    public function it_returns_an_empty_changelog_when_no_transformers_are_registered(): void
    {
        $response = $this->getJson('/api/versions');

        $response->assertOk();
        $response->assertExactJson([
            'baseline' => null,
            'latest'   => null,
            'versions' => [],
        ]);
    }

    #[Test]
    public function the_changelog_route_is_named(): void
    {
        $this->assertSame('/api/versions', route('api-versionist.changelog', [], false));
    }
}
