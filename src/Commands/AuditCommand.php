<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Commands;

use Illuminate\Console\Command;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;
use Versionist\ApiVersionist\Manager\ApiVersionistManager;

/**
 * Audit all registered transformers for correctness and common pitfalls.
 *
 * Validates that every transformer implements the interface correctly,
 * dry-runs upgrade and downgrade pipelines with sample data, and warns
 * about possible no-op bugs (transformers that return data unchanged).
 *
 * ## Sample terminal output
 *
 * ```
 * $ php artisan api:audit
 *
 *   API Versionist Audit
 *   ────────────────────────────────────────
 *
 *   Checking v2 (App\Api\Transformers\V2Transformer)
 *     ✓ Implements VersionTransformerInterface
 *     ✓ version() returns valid string: "v2"
 *     ✓ upgradeRequest() transforms data
 *     ✓ downgradeResponse() transforms data
 *
 *   Checking v3 (App\Api\Transformers\V3Transformer)
 *     ✓ Implements VersionTransformerInterface
 *     ✓ version() returns valid string: "v3"
 *     ⚠ upgradeRequest() returned data unchanged (possible no-op)
 *     ⚠ downgradeResponse() returned data unchanged (possible no-op)
 *
 *   Pipeline Dry-Run
 *   ────────────────────────────────────────
 *     ✓ Upgrade v1 → v3: 2 transformers applied
 *     ✓ Downgrade v3 → v1: 2 transformers applied
 *
 *   Result: 6 passed, 2 warnings, 0 errors
 * ```
 */
class AuditCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'api:audit
        {--from= : Source version for targeted pipeline dry-run}
        {--to= : Target version for targeted pipeline dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit registered transformers for correctness and common pitfalls';

    /**
     * Counters for the summary line.
     *
     * @var int
     */
    private int $passed = 0;

    /**
     * @var int
     */
    private int $warnings = 0;

    /**
     * @var int
     */
    private int $errors = 0;

    /**
     * Execute the console command.
     *
     * @param  ApiVersionistManager  $manager  The versioning manager.
     * @return int                             Exit code (1 on any error).
     */
    public function handle(ApiVersionistManager $manager): int
    {
        $registry = $manager->getRegistry();

        // ── Handle empty registry ──
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

        // ── Phase 1: Validate each transformer ──
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

        // ── Phase 2: Pipeline dry-run ──
        $this->line('');
        $this->line('  <fg=cyan;options=bold>Pipeline Dry-Run</>');
        $this->line('  ' . str_repeat('─', 40));

        $from = $this->option('from');
        $to   = $this->option('to');

        if ($from !== null && $to !== null) {
            // Targeted dry-run.
            $this->dryRunPipeline($manager, $sampleData, $from, $to);
        } else {
            // Full dry-run: baseline → latest and latest → baseline.
            $baseline = $registry->baselineVersion();
            $latest   = $registry->latestVersion();

            $this->dryRunPipeline($manager, $sampleData, $baseline, $latest);
            $this->dryRunPipeline($manager, $sampleData, $latest, $baseline);
        }

        // ── Summary ──
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

    /**
     * Verify the transformer implements VersionTransformerInterface.
     *
     * @param  VersionTransformerInterface  $transformer
     * @return void
     */
    private function auditInterface(VersionTransformerInterface $transformer): void
    {
        // If we got here, it implements the interface (type-hinted).
        $this->pass('Implements VersionTransformerInterface');
    }

    /**
     * Verify the transformer's version() returns a valid, parseable string.
     *
     * @param  VersionTransformerInterface  $transformer
     * @return void
     */
    private function auditVersion(VersionTransformerInterface $transformer): void
    {
        $version = $transformer->version();

        if (! \Versionist\ApiVersionist\Support\VersionParser::isValid($version)) {
            $this->fail("version() returns invalid string: \"{$version}\"");
            return;
        }

        $this->pass("version() returns valid string: \"{$version}\"");
    }

    /**
     * Dry-run upgradeRequest() with sample data and check for no-ops.
     *
     * @param  VersionTransformerInterface  $transformer
     * @param  array<string, mixed>         $sampleData
     * @return void
     */
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

    /**
     * Dry-run downgradeResponse() with sample data and check for no-ops.
     *
     * @param  VersionTransformerInterface  $transformer
     * @param  array<string, mixed>         $sampleData
     * @return void
     */
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

    /**
     * Run a full pipeline dry-run between two versions.
     *
     * Determines direction (upgrade or downgrade) automatically.
     *
     * @param  ApiVersionistManager  $manager
     * @param  array<string, mixed>  $sampleData
     * @param  string                $from
     * @param  string                $to
     * @return void
     */
    private function dryRunPipeline(ApiVersionistManager $manager, array $sampleData, string $from, string $to): void
    {
        $registry  = $manager->getRegistry();
        $direction = \Versionist\ApiVersionist\Support\VersionParser::compare($from, $to);

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

            // Actually run the data through the chain.
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

    /**
     * Print a success line and increment the passed counter.
     *
     * @param  string  $message
     * @return void
     */
    private function pass(string $message): void
    {
        $this->line("    <fg=green>✓</> {$message}");
        $this->passed++;
    }

    /**
     * Print a warning line and increment the warnings counter.
     *
     * Uses $this->line() with a yellow marker. Named warnResult() to
     * avoid colliding with Command::warn().
     *
     * @param  string  $message
     * @return void
     */
    private function warnResult(string $message): void
    {
        $this->line("    <fg=yellow>⚠</> {$message}");
        $this->warnings++;
    }

    /**
     * Print an error line and increment the errors counter.
     *
     * @param  string  $message
     * @return void
     */
    private function fail(string $message): void
    {
        $this->line("    <fg=red>✗</> {$message}");
        $this->errors++;
    }

    /**
     * Extract the short class name (without namespace) for display.
     *
     * @param  string  $fqcn
     * @return string
     */
    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
