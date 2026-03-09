<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Versionist\ApiVersionist\Support\VersionParser;

final class VersionParserTest extends TestCase
{
    // ──────────────────────────────────────────────────────────
    //  parse() — normalization
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_normalizes_bare_number_to_v_prefix(): void
    {
        $this->assertSame('v2', VersionParser::parse('2'));
    }

    #[Test]
    public function it_normalizes_uppercase_v_prefix(): void
    {
        $this->assertSame('v2', VersionParser::parse('V2'));
    }

    #[Test]
    public function it_preserves_already_normalized_format(): void
    {
        $this->assertSame('v2', VersionParser::parse('v2'));
    }

    #[Test]
    public function it_normalizes_minor_version_with_bare_number(): void
    {
        $this->assertSame('v2.1', VersionParser::parse('2.1'));
    }

    #[Test]
    public function it_normalizes_minor_version_with_v_prefix(): void
    {
        $this->assertSame('v2.1', VersionParser::parse('v2.1'));
    }

    #[Test]
    public function it_trims_whitespace_before_parsing(): void
    {
        $this->assertSame('v3', VersionParser::parse('  v3  '));
    }

    #[Test]
    public function it_normalizes_version_zero(): void
    {
        $this->assertSame('v0', VersionParser::parse('0'));
    }

    #[Test]
    public function it_throws_on_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse('');
    }

    #[Test]
    public function it_throws_on_non_numeric_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse('latest');
    }

    #[Test]
    public function it_throws_on_double_dot_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse('v1.2.3');
    }

    #[Test]
    public function it_throws_on_negative_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse('-1');
    }

    // ──────────────────────────────────────────────────────────
    //  compare()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_negative_when_a_is_less_than_b(): void
    {
        $this->assertLessThan(0, VersionParser::compare('v1', 'v2'));
    }

    #[Test]
    public function it_returns_zero_when_versions_are_equal(): void
    {
        $this->assertSame(0, VersionParser::compare('v2', 'v2'));
    }

    #[Test]
    public function it_returns_positive_when_a_is_greater_than_b(): void
    {
        $this->assertGreaterThan(0, VersionParser::compare('v3', 'v2'));
    }

    #[Test]
    public function it_compares_minor_versions_correctly(): void
    {
        $this->assertLessThan(0, VersionParser::compare('v2.1', 'v2.2'));
    }

    #[Test]
    public function it_compares_equal_versions_with_different_formats(): void
    {
        $this->assertSame(0, VersionParser::compare('2', 'v2'));
        $this->assertSame(0, VersionParser::compare('V2', 'v2'));
    }

    #[Test]
    public function it_ranks_major_above_minor(): void
    {
        $this->assertGreaterThan(0, VersionParser::compare('v3', 'v2.9'));
    }

    #[Test]
    public function it_compares_double_digit_versions_numerically_not_lexically(): void
    {
        // String sort would put "v10" < "v9" — this proves numeric comparison.
        $this->assertGreaterThan(0, VersionParser::compare('v10', 'v9'));
        $this->assertLessThan(0, VersionParser::compare('v9', 'v10'));
    }

    // ──────────────────────────────────────────────────────────
    //  extractNumber()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_extracts_major_number_from_simple_version(): void
    {
        $this->assertSame(2, VersionParser::extractNumber('v2'));
    }

    #[Test]
    public function it_extracts_major_number_ignoring_minor(): void
    {
        $this->assertSame(2, VersionParser::extractNumber('v2.1'));
    }

    #[Test]
    public function it_extracts_number_from_bare_string(): void
    {
        $this->assertSame(5, VersionParser::extractNumber('5'));
    }

    // ──────────────────────────────────────────────────────────
    //  isValid()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_validates_correct_formats(): void
    {
        $this->assertTrue(VersionParser::isValid('v1'));
        $this->assertTrue(VersionParser::isValid('V1'));
        $this->assertTrue(VersionParser::isValid('1'));
        $this->assertTrue(VersionParser::isValid('v2.1'));
        $this->assertTrue(VersionParser::isValid('0'));
    }

    #[Test]
    public function it_rejects_invalid_formats(): void
    {
        $this->assertFalse(VersionParser::isValid(''));
        $this->assertFalse(VersionParser::isValid('abc'));
        $this->assertFalse(VersionParser::isValid('v'));
        $this->assertFalse(VersionParser::isValid('v1.2.3'));
        $this->assertFalse(VersionParser::isValid('-1'));
        $this->assertFalse(VersionParser::isValid('v-1'));
    }

    // ──────────────────────────────────────────────────────────
    //  Date-based versions — parse()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_parses_date_version_as_is(): void
    {
        $this->assertSame('2024-01-15', VersionParser::parse('2024-01-15'));
    }

    #[Test]
    public function it_trims_whitespace_on_date_versions(): void
    {
        $this->assertSame('2024-01-15', VersionParser::parse('  2024-01-15  '));
    }

    #[Test]
    public function it_throws_on_invalid_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse('2024-13-45');
    }

    #[Test]
    public function it_throws_on_partial_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse('2024-01');
    }

    // ──────────────────────────────────────────────────────────
    //  Date-based versions — compare()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_compares_date_versions_chronologically(): void
    {
        $this->assertLessThan(0, VersionParser::compare('2024-01-15', '2024-06-01'));
        $this->assertGreaterThan(0, VersionParser::compare('2025-01-01', '2024-12-31'));
        $this->assertSame(0, VersionParser::compare('2024-01-15', '2024-01-15'));
    }

    #[Test]
    public function it_compares_mixed_numeric_and_date_versions(): void
    {
        // Date versions always sort higher than numeric (2024 > any reasonable v-number).
        $this->assertLessThan(0, VersionParser::compare('v10', '2024-01-15'));
        $this->assertGreaterThan(0, VersionParser::compare('2024-01-15', 'v10'));
    }

    // ──────────────────────────────────────────────────────────
    //  Date-based versions — isValid() and isDate()
    // ──────────────────────────────────────────────────────────

    #[Test]
    public function it_validates_date_versions(): void
    {
        $this->assertTrue(VersionParser::isValid('2024-01-15'));
        $this->assertTrue(VersionParser::isValid('2025-12-31'));
        $this->assertTrue(VersionParser::isDate('2024-01-15'));
    }

    #[Test]
    public function it_rejects_invalid_dates(): void
    {
        $this->assertFalse(VersionParser::isValid('2024-13-01'));
        $this->assertFalse(VersionParser::isValid('2024-02-30'));
        $this->assertFalse(VersionParser::isDate('v2'));
        $this->assertFalse(VersionParser::isDate('not-a-date'));
    }

    #[Test]
    public function it_throws_extract_number_on_date_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionParser::extractNumber('2024-01-15');
    }
}
