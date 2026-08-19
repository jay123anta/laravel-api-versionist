<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Table-driven edge cases complementing the scenario tests in VersionParserTest.
 */
final class VersionParserEdgeCasesTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validVersionProvider(): array
    {
        return [
            'plain number'          => ['2', 'v2'],
            'lowercase prefix'      => ['v3', 'v3'],
            'uppercase prefix'      => ['V3', 'v3'],
            'surrounding whitespace' => ['  v2  ', 'v2'],
            'minor version'         => ['v2.1', 'v2.1'],
            'double-digit minor'    => ['v2.10', 'v2.10'],
            'version zero'          => ['v0', 'v0'],
            'double-digit major'    => ['10', 'v10'],
            'leap-day date'         => ['2024-02-29', '2024-02-29'],
            'date passes through'   => ['2024-01-15', '2024-01-15'],
        ];
    }

    #[Test]
    #[DataProvider('validVersionProvider')]
    public function it_normalizes_valid_versions(string $input, string $expected): void
    {
        $this->assertSame($expected, VersionParser::parse($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidVersionProvider(): array
    {
        return [
            'empty string'        => [''],
            'double prefix'       => ['vv2'],
            'negative number'     => ['v-1'],
            'patch segment'       => ['2.1.1'],
            'alphabetic'          => ['abc'],
            'unpadded date'       => ['2024-1-5'],
            'trailing text'       => ['v2 beta'],
            'nonexistent leap day' => ['2023-02-29'],
            'month thirteen'      => ['2024-13-01'],
        ];
    }

    #[Test]
    #[DataProvider('invalidVersionProvider')]
    public function it_rejects_invalid_versions(string $input): void
    {
        $this->assertFalse(VersionParser::isValid($input));

        $this->expectException(InvalidArgumentException::class);
        VersionParser::parse($input);
    }

    /** @return array<string, array{string, string, int}> */
    public static function comparisonProvider(): array
    {
        return [
            'lower vs higher'        => ['v1', 'v2', -1],
            'prefix-insensitive'     => ['2', 'v2', 0],
            'minor beats major only' => ['v2.1', 'v2', 1],
            'dates chronological'    => ['2023-12-31', '2024-01-15', -1],
            'date beats numeric'     => ['2024-01-15', 'v99', 1],
        ];
    }

    #[Test]
    #[DataProvider('comparisonProvider')]
    public function it_compares_versions(string $a, string $b, int $expectedSign): void
    {
        $this->assertSame($expectedSign, VersionParser::compare($a, $b) <=> 0);
    }
}
