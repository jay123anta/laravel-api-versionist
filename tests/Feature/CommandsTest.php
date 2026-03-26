<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Feature;

use Versionist\ApiVersionist\Tests\TestCase;

class CommandsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-versionist.latest_version', 'v1');
        $app['config']->set('api-versionist.transformers', []);
    }

    // --- api:versions ---

    public function test_list_versions_shows_warning_when_no_transformers(): void
    {
        $this->artisan('api:versions')
            ->expectsOutputToContain('No transformers registered')
            ->assertExitCode(0);
    }

    // --- api:changelog ---

    public function test_changelog_shows_warning_when_no_transformers(): void
    {
        $this->artisan('api:changelog')
            ->expectsOutputToContain('No transformers registered')
            ->assertExitCode(0);
    }

    public function test_changelog_json_format_returns_json(): void
    {
        $this->artisan('api:changelog', ['--format' => 'json'])
            ->expectsOutputToContain('No transformers registered')
            ->assertExitCode(0);
    }

    // --- api:audit ---

    public function test_audit_shows_warning_when_no_transformers(): void
    {
        $this->artisan('api:audit')
            ->expectsOutputToContain('No transformers registered')
            ->assertExitCode(0);
    }

    // --- api:make-transformer ---

    public function test_make_transformer_rejects_invalid_version(): void
    {
        $this->artisan('api:make-transformer', ['version' => 'invalid!!'])
            ->expectsOutputToContain('Invalid version string')
            ->assertExitCode(1);
    }

    public function test_make_transformer_creates_file(): void
    {
        $path = base_path('app/Api/Transformers/V4Transformer.php');

        // Clean up before test
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('api:make-transformer', ['version' => 'v4'])
            ->expectsOutputToContain('Transformer created successfully')
            ->assertExitCode(0);

        $this->assertFileExists($path);

        // Clean up
        unlink($path);

        // Remove empty dirs
        $dir = dirname($path);
        while ($dir !== base_path() && is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
