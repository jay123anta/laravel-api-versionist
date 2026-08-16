<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Versionist\ApiVersionist\Support\ChangelogBuilder;

/** Display a changelog of all registered API versions. */
class ChangelogCommand extends Command
{
    protected $signature = 'api:changelog
        {--format=table : Output format: table, json, or markdown}';

    protected $description = 'Display a changelog of all registered API versions';

    public function handle(ChangelogBuilder $builder): int
    {
        $format = $this->option('format');
        $format = is_string($format) ? strtolower($format) : 'table';

        // JSON is a machine contract: always emit a parseable document,
        // including the empty one when no transformers are registered.
        if ($format === 'json') {
            $output = $builder->build(includeTransformerClass: true);

            $this->line(json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $changelog = $builder->build();

        if ($changelog['versions'] === []) {
            $this->warn('No transformers registered.');
            $this->line('');
            $this->line('  Register transformers in <comment>config/api-versionist.php</comment>:');
            $this->line('');
            $this->line("    'transformers' => [");
            $this->line('        App\Api\Transformers\V2Transformer::class,');
            $this->line('    ],');
            $this->line('');

            return self::SUCCESS;
        }

        return match ($format) {
            'markdown', 'md' => $this->outputMarkdown($changelog),
            default => $this->outputTable($changelog),
        };
    }

    /**
     * @param  array{baseline: string|null, latest: string|null, versions: list<array{version: string, is_latest: bool, is_baseline: bool, transformer_class?: string, description: string, released_at: string|null, deprecated: bool, deprecated_at: string|null, sunset_date: string|null}>}  $changelog
     */
    private function outputTable(array $changelog): int
    {
        $baseline = $changelog['baseline'] ?? '';
        $versions = $changelog['versions'];

        $this->line('');
        $this->line('  <fg=cyan;options=bold>API Version Changelog</>');
        $this->line('  ' . str_repeat('─', 46));
        $this->line('');

        foreach ($versions as $entry) {
            $version = $entry['version'];

            if ($entry['is_baseline']) {
                $this->line("  <fg=white;options=bold>{$version}</> <fg=gray>(baseline)</>  ");
                $this->line('  <fg=gray>The original API version before any transforms.</>');
                $this->line('');

                continue;
            }

            if ($entry['is_latest']) {
                $badge = '  <fg=green;options=bold>[LATEST]</>';
            } elseif ($entry['deprecated']) {
                $sunsetText = $entry['sunset_date'] !== null ? " — Sunset: {$entry['sunset_date']}" : '';
                $badge      = "  <fg=red;options=bold>[DEPRECATED{$sunsetText}]</>";
            } else {
                $badge = '  <fg=white>[Active]</>';
            }

            $dateBadge = $entry['released_at'] !== null
                ? "  <fg=gray>Released: {$entry['released_at']}</>"
                : '';

            $this->line("  <fg=white;options=bold>{$version}</>{$badge}{$dateBadge}");
            $this->line("  <fg=gray>{$entry['description']}</>");
            $this->line('');
        }

        $this->line('  ' . str_repeat('─', 46));
        $count = count($versions);
        $this->line("  <fg=gray>{$count} versions registered (baseline: {$baseline})</>");
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @param  array{baseline: string|null, latest: string|null, versions: list<array{version: string, is_latest: bool, is_baseline: bool, transformer_class?: string, description: string, released_at: string|null, deprecated: bool, deprecated_at: string|null, sunset_date: string|null}>}  $changelog
     */
    private function outputMarkdown(array $changelog): int
    {
        $this->line('# API Changelog');
        $this->line('');

        foreach (array_reverse($changelog['versions']) as $entry) {
            $version = $entry['version'];

            if ($entry['is_baseline']) {
                $this->line("## {$version} — Baseline");
                $this->line('');
                $this->line('The original API version before any transformations were defined.');
                $this->line('');

                continue;
            }

            $releasedAt = $entry['released_at'] ?? 'Unreleased';

            $header = "## {$version} — {$releasedAt}";

            $badges = [];
            if ($entry['is_latest']) {
                $badges[] = '`LATEST`';
            }
            if ($entry['deprecated']) {
                $badges[] = $entry['sunset_date'] !== null
                    ? "`DEPRECATED — Sunset: {$entry['sunset_date']}`"
                    : '`DEPRECATED`';
            }

            if ($badges !== []) {
                $header .= ' ' . implode(' ', $badges);
            }

            $this->line($header);
            $this->line('');
            $this->line("- {$entry['description']}");
            $this->line('');
        }

        return self::SUCCESS;
    }
}
