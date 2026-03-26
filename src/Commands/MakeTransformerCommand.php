<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Versionist\ApiVersionist\Support\VersionParser;

/** Scaffold a new API version transformer class. */
class MakeTransformerCommand extends Command
{
    protected $signature = 'api:make-transformer
        {version : The API version to create a transformer for (e.g. v2, 3, v2.1, 2024-01-15)}
        {--path=app/Api/Transformers : The directory to place the transformer in}
        {--force : Overwrite the file if it already exists}';

    protected $description = 'Scaffold a new API version transformer class';

    public function handle(Filesystem $files): int
    {
        $rawVersion = $this->argument('version');

        if (! VersionParser::isValid($rawVersion)) {
            $this->error("Invalid version string: \"{$rawVersion}\"");
            $this->line('  Expected format: v2, 3, v2.1, 2024-01-15, etc.');

            return self::FAILURE;
        }

        $version = VersionParser::parse($rawVersion);

        $classVersion = strtoupper(str_replace(['.', '-'], '_', ltrim($version, 'v')));
        $className    = "V{$classVersion}Transformer";

        $relativePath = str_replace('\\', '/', $this->option('path'));
        $relativePath = rtrim($relativePath, '/');

        $namespace = str_replace('/', '\\', ucfirst($relativePath));

        $filePath = base_path($relativePath . '/' . $className . '.php');

        $this->line('');
        $this->line("  Creating transformer for version <fg=cyan;options=bold>{$version}</>...");
        $this->line('');

        if ($files->exists($filePath) && ! $this->option('force')) {
            $this->error("File already exists: {$filePath}");
            $this->line('  Use <comment>--force</comment> to overwrite.');

            return self::FAILURE;
        }

        $stubPath = $this->resolveStubPath($files);

        if ($stubPath === null) {
            $this->error('Transformer stub file not found.');
            $this->line('  Publish stubs with: <comment>php artisan vendor:publish --tag=api-versionist-stubs</comment>');

            return self::FAILURE;
        }

        $stub = $files->get($stubPath);

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ version }}', '{{ date }}'],
            [$namespace, $className, $version, date('Y-m-d')],
            $stub,
        );

        $directory = dirname($filePath);

        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0o755, true);
        }

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

    private function resolveStubPath(Filesystem $files): ?string
    {
        $publishedPath = base_path('stubs/api-versionist/transformer.stub');
        if ($files->exists($publishedPath)) {
            return $publishedPath;
        }

        $packagePath = dirname(__DIR__, 2) . '/stubs/transformer.stub';
        if ($files->exists($packagePath)) {
            return $packagePath;
        }

        return null;
    }
}
