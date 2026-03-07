<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/**
 * List all registered API versions in a tabular format.
 *
 * Optionally shows the step-by-step upgrade and downgrade chain paths
 * between each version pair with the --chains flag.
 *
 * ## Sample terminal output
 *
 * ```
 *  API Versions
 *
 *  +---------+----------------------------------+--------------+-------------+
 *  | Version | Transformer Class                | Status       | Released At |
 *  +---------+----------------------------------+--------------+-------------+
 *  | v1      | —                                | Baseline     | —           |
 *  | v2      | App\Api\Transformers\V2Transform | Active       | 2024-03-15  |
 *  | v3      | App\Api\Transformers\V3Transform | LATEST       | 2024-09-01  |
 *  +---------+----------------------------------+--------------+-------------+
 *
 *  --chains output:
 *
 *  Upgrade Chains
 *    v1 → v3 : V2Transformer → V3Transformer
 *    v1 → v2 : V2Transformer
 *    v2 → v3 : V3Transformer
 *
 *  Downgrade Chains
 *    v3 → v1 : V3Transformer → V2Transformer
 *    v3 → v2 : V3Transformer
 *    v2 → v1 : V2Transformer
 * ```
 */
class ListVersionsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'api:versions
        {--chains : Show step-by-step upgrade and downgrade chain paths}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered API versions and their transformers';

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

        // ── Handle empty registry ──
        if ($registry->all() === []) {
            $this->warn('No transformers registered.');
            $this->line('');
            $this->line('  Generate one with: <comment>php artisan api:make-transformer v2</comment>');
            $this->line('');

            return self::SUCCESS;
        }

        $baseline = $registry->baselineVersion();
        $latest   = $registry->latestVersion();
        $versions = $registry->getVersions();

        // ── Build table rows ──
        $rows = [];

        foreach ($versions as $version) {
            $isBaseline = ($version === $baseline);
            $isLatest   = ($version === $latest);

            if ($isBaseline) {
                $rows[] = [
                    $version,
                    '<fg=gray>—</>',
                    '<fg=gray>Baseline</>',
                    '<fg=gray>—</>',
                ];
                continue;
            }

            $transformer = $registry->getTransformer($version);

            // ── Status with color ──
            if ($isLatest) {
                $status = '<fg=green;options=bold>LATEST</>';
            } elseif ($negotiator->isDeprecated($version)) {
                $sunsetDate = $negotiator->getSunsetDate($version);
                $status = $sunsetDate !== null
                    ? "<fg=red>Deprecated (sunset: {$sunsetDate})</>"
                    : '<fg=red>Deprecated</>';
            } else {
                $status = 'Active';
            }

            $releasedAt = $transformer->releasedAt() ?? '<fg=gray>—</>';

            $rows[] = [
                $version,
                $transformer::class,
                $status,
                $releasedAt,
            ];
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>API Versions</>');
        $this->line('');

        $this->table(
            ['Version', 'Transformer Class', 'Status', 'Released At'],
            $rows,
        );

        // ── Optional chain output ──
        if ($this->option('chains')) {
            $this->renderChains($manager, $versions, $baseline);
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Render upgrade and downgrade chains between all version pairs.
     *
     * @param  ApiVersionistManager  $manager
     * @param  array<int, string>    $versions  All known versions (ascending).
     * @param  string                $baseline  The baseline version.
     * @return void
     */
    private function renderChains(ApiVersionistManager $manager, array $versions, string $baseline): void
    {
        $registry = $manager->getRegistry();

        // ── Upgrade chains ──
        $this->line('');
        $this->line('  <fg=cyan;options=bold>Upgrade Chains</>');

        $hasUpgrade = false;

        for ($i = 0, $count = count($versions); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $from  = $versions[$i];
                $to    = $versions[$j];
                $chain = $registry->getUpgradeChain($from, $to);

                if ($chain === []) {
                    continue;
                }

                $steps = array_map(
                    fn ($t) => $this->shortClassName($t::class),
                    $chain,
                );

                $this->line(
                    "    <fg=white>{$from}</> <fg=gray>→</> <fg=green>{$to}</>  :  "
                    . implode(' <fg=gray>→</> ', $steps),
                );
                $hasUpgrade = true;
            }
        }

        if (! $hasUpgrade) {
            $this->line('    <fg=gray>No upgrade chains available.</>');
        }

        // ── Downgrade chains ──
        $this->line('');
        $this->line('  <fg=cyan;options=bold>Downgrade Chains</>');

        $hasDowngrade = false;

        for ($i = count($versions) - 1; $i >= 0; $i--) {
            for ($j = $i - 1; $j >= 0; $j--) {
                $from  = $versions[$i];
                $to    = $versions[$j];
                $chain = $registry->getDowngradeChain($from, $to);

                if ($chain === []) {
                    continue;
                }

                $steps = array_map(
                    fn ($t) => $this->shortClassName($t::class),
                    $chain,
                );

                $this->line(
                    "    <fg=white>{$from}</> <fg=gray>→</> <fg=red>{$to}</>  :  "
                    . implode(' <fg=gray>→</> ', $steps),
                );
                $hasDowngrade = true;
            }
        }

        if (! $hasDowngrade) {
            $this->line('    <fg=gray>No downgrade chains available.</>');
        }
    }

    /**
     * Extract the short class name (without namespace) for display.
     *
     * @param  string  $fqcn  Fully-qualified class name.
     * @return string         Short class name.
     */
    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
