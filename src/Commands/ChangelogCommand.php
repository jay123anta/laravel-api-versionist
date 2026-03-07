<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/**
 * Display a changelog of all registered API versions.
 *
 * Supports three output formats: a colored ASCII table (default),
 * structured JSON, and Keep-a-Changelog-style Markdown.
 *
 * ## Sample terminal output (table format)
 *
 * ```
 * ┌──────────────────────────────────────────────────┐
 * │          API Version Changelog                   │
 * ├──────────────────────────────────────────────────┤
 * │                                                  │
 * │  v1 (baseline)                                   │
 * │  The original API version before any transforms. │
 * │                                                  │
 * │  v2  [Active]  Released: 2024-03-15              │
 * │  Renamed name to full_name.                      │
 * │                                                  │
 * │  v3  [LATEST]  Released: 2024-09-01              │
 * │  Nested email into contact.email.                │
 * │                                                  │
 * ├──────────────────────────────────────────────────┤
 * │  3 versions registered (baseline: v1)            │
 * └──────────────────────────────────────────────────┘
 * ```
 */
class ChangelogCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'api:changelog
        {--format=table : Output format: table, json, or markdown}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display a changelog of all registered API versions';

    /**
     * Execute the console command.
     *
     * @param  ApiVersionistManager  $manager     The versioning manager.
     * @param  VersionNegotiator     $negotiator  The version negotiator (for deprecation info).
     * @return int                                Exit code.
     */
    public function handle(ApiVersionistManager $manager, VersionNegotiator $negotiator): int
    {
        $registry = $manager->getRegistry();
        $config   = $manager->getConfig();

        // ── Handle empty registry ──
        if ($registry->all() === []) {
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

        $format = strtolower($this->option('format'));

        return match ($format) {
            'json'     => $this->outputJson($manager, $negotiator),
            'markdown', 'md' => $this->outputMarkdown($manager, $negotiator),
            default    => $this->outputTable($manager, $negotiator),
        };
    }

    /**
     * Render the changelog as a colored ASCII table.
     *
     * @param  ApiVersionistManager  $manager
     * @param  VersionNegotiator     $negotiator
     * @return int
     */
    private function outputTable(ApiVersionistManager $manager, VersionNegotiator $negotiator): int
    {
        $registry = $manager->getRegistry();
        $config   = $manager->getConfig();

        $baseline = $registry->baselineVersion();
        $latest   = $registry->latestVersion();
        $versions = $registry->getVersions();

        $this->line('');
        $this->line('  <fg=cyan;options=bold>API Version Changelog</>');
        $this->line('  ' . str_repeat('─', 46));
        $this->line('');

        foreach ($versions as $version) {
            if ($version === $baseline) {
                // Baseline version — no transformer exists.
                $this->line("  <fg=white;options=bold>{$version}</> <fg=gray>(baseline)</>  ");
                $this->line('  <fg=gray>The original API version before any transforms.</>');
                $this->line('');
                continue;
            }

            $transformer = $registry->getTransformer($version);

            // ── Build the status badge ──
            $badge = '';
            if ($version === $latest) {
                $badge = '  <fg=green;options=bold>[LATEST]</>';
            } elseif ($negotiator->isDeprecated($version)) {
                $sunsetDate = $negotiator->getSunsetDate($version);
                $sunsetText = $sunsetDate !== null ? " — Sunset: {$sunsetDate}" : '';
                $badge = "  <fg=red;options=bold>[DEPRECATED{$sunsetText}]</>";
            } else {
                $badge = '  <fg=white>[Active]</>';
            }

            // ── Build the released date ──
            $releasedAt = $transformer->releasedAt();
            $dateBadge  = $releasedAt !== null
                ? "  <fg=gray>Released: {$releasedAt}</>"
                : '';

            $this->line("  <fg=white;options=bold>{$version}</>{$badge}{$dateBadge}");
            $this->line("  <fg=gray>{$transformer->description()}</>");
            $this->line('');
        }

        $this->line('  ' . str_repeat('─', 46));
        $count = count($versions);
        $this->line("  <fg=gray>{$count} versions registered (baseline: {$baseline})</>");
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Render the changelog as structured JSON.
     *
     * @param  ApiVersionistManager  $manager
     * @param  VersionNegotiator     $negotiator
     * @return int
     */
    private function outputJson(ApiVersionistManager $manager, VersionNegotiator $negotiator): int
    {
        $registry = $manager->getRegistry();

        $baseline = $registry->baselineVersion();
        $latest   = $registry->latestVersion();

        $versionsData = [];

        foreach ($registry->getVersions() as $version) {
            $entry = [
                'version'    => $version,
                'is_latest'  => $version === $latest,
                'is_baseline' => $version === $baseline,
            ];

            if ($version !== $baseline) {
                $transformer = $registry->getTransformer($version);
                $entry['transformer_class'] = $transformer::class;
                $entry['description']       = $transformer->description();
                $entry['released_at']       = $transformer->releasedAt();
            } else {
                $entry['description'] = 'Baseline version (no transformer)';
                $entry['released_at'] = null;
            }

            $entry['deprecated']  = $negotiator->isDeprecated($version);
            $entry['sunset_date'] = $negotiator->getSunsetDate($version);

            $versionsData[] = $entry;
        }

        $output = [
            'baseline' => $baseline,
            'latest'   => $latest,
            'versions' => $versionsData,
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Render the changelog as Keep-a-Changelog-style Markdown.
     *
     * @param  ApiVersionistManager  $manager
     * @param  VersionNegotiator     $negotiator
     * @return int
     */
    private function outputMarkdown(ApiVersionistManager $manager, VersionNegotiator $negotiator): int
    {
        $registry = $manager->getRegistry();

        $baseline = $registry->baselineVersion();
        $latest   = $registry->latestVersion();

        $this->line('# API Changelog');
        $this->line('');

        // Iterate in reverse (newest first) for changelog convention.
        $versions = array_reverse($registry->getVersions());

        foreach ($versions as $version) {
            if ($version === $baseline) {
                $this->line("## {$version} — Baseline");
                $this->line('');
                $this->line('The original API version before any transformations were defined.');
                $this->line('');
                continue;
            }

            $transformer = $registry->getTransformer($version);
            $releasedAt  = $transformer->releasedAt() ?? 'Unreleased';

            // Build header with date.
            $header = "## {$version} — {$releasedAt}";

            // Add badges.
            $badges = [];
            if ($version === $latest) {
                $badges[] = '`LATEST`';
            }
            if ($negotiator->isDeprecated($version)) {
                $sunsetDate = $negotiator->getSunsetDate($version);
                $badges[] = $sunsetDate !== null
                    ? "`DEPRECATED — Sunset: {$sunsetDate}`"
                    : '`DEPRECATED`';
            }

            if ($badges !== []) {
                $header .= ' ' . implode(' ', $badges);
            }

            $this->line($header);
            $this->line('');
            $this->line("- {$transformer->description()}");
            $this->line('');
        }

        return self::SUCCESS;
    }
}
