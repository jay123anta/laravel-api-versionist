<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Exceptions\UnknownVersionException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Tests\TestCase;

final class TransformerRegistryTest extends TestCase
{
    private TransformerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new TransformerRegistry();
    }

    // ──────────────────────────────────────────────────────────
    //  Registration and sorting
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_registers_a_single_transformer(): void
    {
        $t = $this->makeTransformer('v2');
        $this->registry->register($t);

        $this->assertSame(['v2' => $t], $this->registry->all());
    }

    #[Test]
    public function it_sorts_transformers_in_ascending_order(): void
    {
        $t3 = $this->makeTransformer('v3');
        $t2 = $this->makeTransformer('v2');
        $t5 = $this->makeTransformer('v5');

        $this->registry->register($t3);
        $this->registry->register($t2);
        $this->registry->register($t5);

        $this->assertSame(['v2', 'v3', 'v5'], array_keys($this->registry->all()));
    }

    #[Test]
    public function it_registers_many_at_once(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('v3'),
            $this->makeTransformer('v2'),
        ]);

        $this->assertSame(['v2', 'v3'], array_keys($this->registry->all()));
    }

    #[Test]
    public function it_replaces_transformer_for_same_version(): void
    {
        $t1 = $this->makeTransformer('v2', desc: 'first');
        $t2 = $this->makeTransformer('v2', desc: 'second');

        $this->registry->register($t1);
        $this->registry->register($t2);

        $this->assertSame('second', $this->registry->getTransformer('v2')->description());
    }

    // ──────────────────────────────────────────────────────────
    //  Baseline version
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_derives_baseline_one_major_below_lowest(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->assertSame('v1', $this->registry->baselineVersion());
    }

    #[Test]
    public function it_derives_baseline_v0_when_lowest_is_v1(): void
    {
        $this->registry->register($this->makeTransformer('v1'));

        $this->assertSame('v0', $this->registry->baselineVersion());
    }

    #[Test]
    public function it_throws_on_baseline_for_empty_registry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->registry->baselineVersion();
    }

    // ──────────────────────────────────────────────────────────
    //  Latest version
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_highest_version_as_latest(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('v2'),
            $this->makeTransformer('v5'),
            $this->makeTransformer('v3'),
        ]);

        $this->assertSame('v5', $this->registry->latestVersion());
    }

    #[Test]
    public function it_throws_on_latest_for_empty_registry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->registry->latestVersion();
    }

    // ──────────────────────────────────────────────────────────
    //  getVersions() — includes baseline
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_all_versions_including_baseline(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('v2'),
            $this->makeTransformer('v3'),
        ]);

        $this->assertSame(['v1', 'v2', 'v3'], $this->registry->getVersions());
    }

    #[Test]
    public function it_returns_empty_array_for_empty_registry(): void
    {
        $this->assertSame([], $this->registry->getVersions());
    }

    // ──────────────────────────────────────────────────────────
    //  isKnownVersion()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_recognizes_registered_version(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->assertTrue($this->registry->isKnownVersion('v2'));
    }

    #[Test]
    public function it_recognizes_baseline_version(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->assertTrue($this->registry->isKnownVersion('v1'));
    }

    #[Test]
    public function it_rejects_unknown_version(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->assertFalse($this->registry->isKnownVersion('v99'));
    }

    #[Test]
    public function it_rejects_invalid_version_string(): void
    {
        $this->assertFalse($this->registry->isKnownVersion('garbage'));
    }

    // ──────────────────────────────────────────────────────────
    //  Upgrade chain
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_builds_upgrade_chain_from_baseline_to_latest(): void
    {
        $t2 = $this->makeTransformer('v2');
        $t3 = $this->makeTransformer('v3');
        $this->registry->register($t2)->register($t3);

        $chain = $this->registry->getUpgradeChain('v1', 'v3');

        $this->assertCount(2, $chain);
        $this->assertSame($t2, $chain[0]);
        $this->assertSame($t3, $chain[1]);
    }

    #[Test]
    public function it_builds_partial_upgrade_chain(): void
    {
        $t2 = $this->makeTransformer('v2');
        $t3 = $this->makeTransformer('v3');
        $t4 = $this->makeTransformer('v4');
        $this->registry->registerMany([$t2, $t3, $t4]);

        $chain = $this->registry->getUpgradeChain('v2', 'v4');

        $this->assertCount(2, $chain);
        $this->assertSame($t3, $chain[0]);
        $this->assertSame($t4, $chain[1]);
    }

    #[Test]
    public function it_returns_empty_chain_for_same_version(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $chain = $this->registry->getUpgradeChain('v2', 'v2');

        $this->assertSame([], $chain);
    }

    #[Test]
    public function it_returns_empty_chain_when_from_is_higher_than_to(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('v2'),
            $this->makeTransformer('v3'),
        ]);

        $chain = $this->registry->getUpgradeChain('v3', 'v1');

        $this->assertSame([], $chain);
    }

    #[Test]
    public function it_throws_for_unknown_version_in_upgrade_chain(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->expectException(UnknownVersionException::class);
        $this->registry->getUpgradeChain('v1', 'v99');
    }

    // ──────────────────────────────────────────────────────────
    //  Downgrade chain
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_builds_downgrade_chain_in_reverse_order(): void
    {
        $t2 = $this->makeTransformer('v2');
        $t3 = $this->makeTransformer('v3');
        $this->registry->register($t2)->register($t3);

        $chain = $this->registry->getDowngradeChain('v3', 'v1');

        $this->assertCount(2, $chain);
        $this->assertSame($t3, $chain[0]);
        $this->assertSame($t2, $chain[1]);
    }

    #[Test]
    public function it_returns_empty_downgrade_chain_for_same_version(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->assertSame([], $this->registry->getDowngradeChain('v2', 'v2'));
    }

    // ──────────────────────────────────────────────────────────
    //  getTransformer()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_retrieves_transformer_by_version(): void
    {
        $t = $this->makeTransformer('v2', desc: 'my transformer');
        $this->registry->register($t);

        $this->assertSame($t, $this->registry->getTransformer('v2'));
    }

    #[Test]
    public function it_throws_when_getting_unregistered_version(): void
    {
        $this->registry->register($this->makeTransformer('v2'));

        $this->expectException(UnknownVersionException::class);
        $this->registry->getTransformer('v99');
    }

    // ──────────────────────────────────────────────────────────
    //  Single transformer edge case
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_handles_single_transformer_upgrade(): void
    {
        $t2 = $this->makeTransformer('v2');
        $this->registry->register($t2);

        $chain = $this->registry->getUpgradeChain('v1', 'v2');

        $this->assertCount(1, $chain);
        $this->assertSame($t2, $chain[0]);
    }

    #[Test]
    public function it_handles_single_transformer_downgrade(): void
    {
        $t2 = $this->makeTransformer('v2');
        $this->registry->register($t2);

        $chain = $this->registry->getDowngradeChain('v2', 'v1');

        $this->assertCount(1, $chain);
        $this->assertSame($t2, $chain[0]);
    }

    // ──────────────────────────────────────────────────────────
    //  Date-based versions
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_registers_and_sorts_date_based_transformers(): void
    {
        $t1 = $this->makeTransformer('2024-06-01');
        $t2 = $this->makeTransformer('2024-01-15');
        $t3 = $this->makeTransformer('2025-01-01');

        $this->registry->register($t1);
        $this->registry->register($t2);
        $this->registry->register($t3);

        $this->assertSame(['2024-01-15', '2024-06-01', '2025-01-01'], array_keys($this->registry->all()));
    }

    #[Test]
    public function it_derives_date_baseline_as_one_day_before_lowest(): void
    {
        $this->registry->register($this->makeTransformer('2024-01-15'));

        $this->assertSame('2024-01-14', $this->registry->baselineVersion());
    }

    #[Test]
    public function it_builds_upgrade_chain_for_date_versions(): void
    {
        $t1 = $this->makeTransformer('2024-06-01');
        $t2 = $this->makeTransformer('2025-01-01');
        $this->registry->register($t1)->register($t2);

        $chain = $this->registry->getUpgradeChain('2024-05-31', '2025-01-01');

        $this->assertCount(2, $chain);
        $this->assertSame($t1, $chain[0]);
        $this->assertSame($t2, $chain[1]);
    }

    #[Test]
    public function it_builds_downgrade_chain_for_date_versions(): void
    {
        $t1 = $this->makeTransformer('2024-06-01');
        $t2 = $this->makeTransformer('2025-01-01');
        $this->registry->register($t1)->register($t2);

        $chain = $this->registry->getDowngradeChain('2025-01-01', '2024-05-31');

        $this->assertCount(2, $chain);
        $this->assertSame($t2, $chain[0]);
        $this->assertSame($t1, $chain[1]);
    }

    #[Test]
    public function it_returns_latest_date_version(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('2024-01-15'),
            $this->makeTransformer('2025-01-01'),
            $this->makeTransformer('2024-06-01'),
        ]);

        $this->assertSame('2025-01-01', $this->registry->latestVersion());
    }

    #[Test]
    public function it_includes_date_baseline_in_versions_list(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('2024-06-01'),
            $this->makeTransformer('2025-01-01'),
        ]);

        $this->assertSame(['2024-05-31', '2024-06-01', '2025-01-01'], $this->registry->getVersions());
    }
}
