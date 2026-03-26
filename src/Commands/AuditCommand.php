<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;
use Versionist\ApiVersionist\Support\VersionParser;

/** Audit registered transformers for correctness and common pitfalls. */
class AuditCommand extends Command
{
    protected $signature = 'api:audit
        {--from= : Source version for targeted pipeline dry-run}
        {--to= : Target version for targeted pipeline dry-run}';

    protected $description = 'Audit registered transformers for correctness and common pitfalls';

    private int $passed = 0;
    private int $warnings = 0;
    private int $errors = 0;

    public function handle(ApiVersionistManager $manager): int
    {
        $registry = $manager->getRegistry();

        if ($registry->all() === []) {
            $this->warn('No transformers registered — nothing to audit.');
            $this->line('');
            $this->line('  Register transformers in <comment>config/api-versionist.php</comment>:');
            $this->line('');
            $this->line("    'transformers' => [");
            $this->line('        App\Api\Transformers\V2Transformer::class,');
            $this->line('    ],');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>API Versionist Audit</>');
        $this->line('  ' . str_repeat('─', 40));

        $sampleData = ['id' => 1, 'test' => true];

        foreach ($registry->all() as $version => $transformer) {
            $this->line('');
            $class = $transformer::class;
            $this->line("  Checking <fg=white;options=bold>{$version}</> <fg=gray>({$class})</>");

            $this->auditInterface($transformer);
            $this->auditVersion($transformer);
            $this->auditUpgradeRequest($transformer, $sampleData);
            $this->auditDowngradeResponse($transformer, $sampleData);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Pipeline Dry-Run</>');
        $this->line('  ' . str_repeat('─', 40));

        $from = $this->option('from');
        $to   = $this->option('to');

        if ($from !== null && $to !== null) {
            $this->dryRunPipeline($manager, $sampleData, $from, $to);
        } else {
            $baseline = $registry->baselineVersion();
            $latest   = $registry->latestVersion();

            $this->dryRunPipeline($manager, $sampleData, $baseline, $latest);
            $this->dryRunPipeline($manager, $sampleData, $latest, $baseline);
        }

        $this->line('');
        $this->line('  ' . str_repeat('─', 40));

        $summaryParts = [];
        $summaryParts[] = "<fg=green>{$this->passed} passed</>";
        if ($this->warnings > 0) {
            $summaryParts[] = "<fg=yellow>{$this->warnings} warnings</>";
        }
        if ($this->errors > 0) {
            $summaryParts[] = "<fg=red>{$this->errors} errors</>";
        }

        $this->line('  <fg=white;options=bold>Result:</> ' . implode(', ', $summaryParts));
        $this->line('');

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function auditInterface(VersionTransformerInterface $transformer): void
    {
        $this->pass('Implements VersionTransformerInterface');
    }

    private function auditVersion(VersionTransformerInterface $transformer): void
    {
        $version = $transformer->version();

        if (! VersionParser::isValid($version)) {
            $this->fail("version() returns invalid string: \"{$version}\"");
            return;
        }

        $this->pass("version() returns valid string: \"{$version}\"");
    }

    private function auditUpgradeRequest(VersionTransformerInterface $transformer, array $sampleData): void
    {
        try {
            $result = $transformer->upgradeRequest($sampleData);

            if ($result === $sampleData) {
                $this->warnResult('upgradeRequest() returned data unchanged (possible no-op)');
            } else {
                $this->pass('upgradeRequest() transforms data');
            }
        } catch (\Throwable $e) {
            $this->fail('upgradeRequest() threw ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function auditDowngradeResponse(VersionTransformerInterface $transformer, array $sampleData): void
    {
        try {
            $result = $transformer->downgradeResponse($sampleData);

            if ($result === $sampleData) {
                $this->warnResult('downgradeResponse() returned data unchanged (possible no-op)');
            } else {
                $this->pass('downgradeResponse() transforms data');
            }
        } catch (\Throwable $e) {
            $this->fail('downgradeResponse() threw ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function dryRunPipeline(ApiVersionistManager $manager, array $sampleData, string $from, string $to): void
    {
        $registry  = $manager->getRegistry();
        $direction = VersionParser::compare($from, $to);

        if ($direction === 0) {
            $this->line("    <fg=gray>Skipping {$from} → {$to} (same version)</>");
            return;
        }

        $isUpgrade = $direction < 0;
        $label     = $isUpgrade ? 'Upgrade' : 'Downgrade';

        try {
            $chain = $isUpgrade
                ? $registry->getUpgradeChain($from, $to)
                : $registry->getDowngradeChain($from, $to);

            if ($isUpgrade) {
                $data = $sampleData;
                foreach ($chain as $t) {
                    $data = $t->upgradeRequest($data);
                }
            } else {
                $data = $sampleData;
                foreach ($chain as $t) {
                    $data = $t->downgradeResponse($data);
                }
            }

            $count = count($chain);
            $steps = array_map(fn ($t) => $this->shortClassName($t::class), $chain);

            $this->pass("{$label} {$from} → {$to}: {$count} transformer(s) applied"
                . ($steps !== [] ? ' [' . implode(' → ', $steps) . ']' : ''));
        } catch (\Throwable $e) {
            $this->fail("{$label} {$from} → {$to}: " . $e::class . ' — ' . $e->getMessage());
        }
    }

    private function pass(string $message): void
    {
        $this->line("    <fg=green>✓</> {$message}");
        $this->passed++;
    }

    private function warnResult(string $message): void
    {
        $this->line("    <fg=yellow>⚠</> {$message}");
        $this->warnings++;
    }

    private function fail(string $message): void
    {
        $this->line("    <fg=red>✗</> {$message}");
        $this->errors++;
    }

    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
