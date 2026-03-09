<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/** List all registered API versions and their transformers. */
class ListVersionsCommand extends Command
{
    protected $signature = 'api:versions
        {--chains : Show step-by-step upgrade and downgrade chain paths}';

    protected $description = 'List all registered API versions and their transformers';

    public function handle(ApiVersionistManager $manager, VersionNegotiator $negotiator): int
    {
        $registry = $manager->getRegistry();

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

        if ($this->option('chains')) {
            $this->renderChains($manager, $versions, $baseline);
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function renderChains(ApiVersionistManager $manager, array $versions, string $baseline): void
    {
        $registry = $manager->getRegistry();

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

    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
