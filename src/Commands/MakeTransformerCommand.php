<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Scaffold a new API version transformer from the package stub.
 *
 * Reads the transformer stub, replaces all placeholders, writes the
 * file to disk, and prints a copy-paste config registration snippet.
 *
 * ## Sample terminal output
 *
 * ```
 * $ php artisan api:make-transformer v4
 *
 *   Creating transformer for version v4...
 *
 *   ✓ Transformer created successfully!
 *
 *   File: app/Api/Transformers/V4Transformer.php
 *
 *   Register it in config/api-versionist.php:
 *
 *     'transformers' => [
 *         // ... existing transformers
 *         App\Api\Transformers\V4Transformer::class,
 *     ],
 * ```
 */
class MakeTransformerCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'api:make-transformer
        {version : The API version to create a transformer for (e.g. v2, 3, v2.1)}
        {--path=app/Api/Transformers : The directory to place the transformer in}
        {--force : Overwrite the file if it already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold a new API version transformer class';

    /**
     * Execute the console command.
     *
     * @param  Filesystem  $files  The filesystem instance (injected by Laravel).
     * @return int                 Exit code.
     */
    public function handle(Filesystem $files): int
    {
        $rawVersion = $this->argument('version');

        // ── Validate version string ──
        if (! VersionParser::isValid($rawVersion)) {
            $this->error("Invalid version string: \"{$rawVersion}\"");
            $this->line('  Expected format: v2, 3, v2.1, etc.');

            return self::FAILURE;
        }

        $version = VersionParser::parse($rawVersion);

        // ── Derive class name and namespace ──
        // "v2" → "V2Transformer", "v2.1" → "V2_1Transformer"
        $classVersion = strtoupper(str_replace('.', '_', ltrim($version, 'v')));
        $className    = "V{$classVersion}Transformer";

        $relativePath = str_replace('\\', '/', $this->option('path'));
        $relativePath = rtrim($relativePath, '/');

        // Convert filesystem path to namespace.
        // "app/Api/Transformers" → "App\Api\Transformers"
        $namespace = str_replace('/', '\\', ucfirst($relativePath));

        $filePath = base_path($relativePath . '/' . $className . '.php');

        $this->line('');
        $this->line("  Creating transformer for version <fg=cyan;options=bold>{$version}</>...");
        $this->line('');

        // ── Check if file exists ──
        if ($files->exists($filePath) && ! $this->option('force')) {
            $this->error("File already exists: {$filePath}");
            $this->line('  Use <comment>--force</comment> to overwrite.');

            return self::FAILURE;
        }

        // ── Read the stub ──
        $stubPath = $this->resolveStubPath($files);

        if ($stubPath === null) {
            $this->error('Transformer stub file not found.');
            $this->line('  Publish stubs with: <comment>php artisan vendor:publish --tag=api-versionist-stubs</comment>');

            return self::FAILURE;
        }

        $stub = $files->get($stubPath);

        // ── Replace placeholders ──
        $content = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ version }}', '{{ date }}'],
            [$namespace, $className, $version, date('Y-m-d')],
            $stub,
        );

        // ── Create directory if needed ──
        $directory = dirname($filePath);

        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
        }

        // ── Write the file ──
        $files->put($filePath, $content);

        $this->info('  Transformer created successfully!');
        $this->line('');
        $this->line("  <fg=white;options=bold>File:</> {$filePath}");
        $this->line('');
        $this->line('  Register it in <comment>config/api-versionist.php</comment>:');
        $this->line('');
        $this->line("    <fg=gray>'transformers' => [</>");
        $this->line("        <fg=gray>// ... existing transformers</>");

        $fqcn = $namespace . '\\' . $className;
        $this->line("        <fg=green>{$fqcn}::class,</>");

        $this->line("    <fg=gray>],</>");
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Resolve the transformer stub path.
     *
     * Checks for a published stub first (in the app's stubs/ directory),
     * then falls back to the package's bundled stub.
     *
     * @param  Filesystem  $files
     * @return string|null  The absolute path to the stub, or null if not found.
     */
    private function resolveStubPath(Filesystem $files): ?string
    {
        // Check for user-published stub first.
        $publishedPath = base_path('stubs/api-versionist/transformer.stub');
        if ($files->exists($publishedPath)) {
            return $publishedPath;
        }

        // Fall back to the package's bundled stub.
        $packagePath = dirname(__DIR__, 2) . '/stubs/transformer.stub';
        if ($files->exists($packagePath)) {
            return $packagePath;
        }

        return null;
    }
}
