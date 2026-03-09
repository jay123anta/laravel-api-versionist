<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Support;

/**
 * Parses, normalizes, and compares API version strings.
 *
 * Accepts "v2", "2", "v2.1", "2024-01-15" — normalizes to "v2", "v2.1", or "2024-01-15".
 */
final class VersionParser
{
    private const NUMERIC_PATTERN = '/^[vV]?(\d+)(\.\d+)?$/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private function __construct()
    {
    }

    /**
     * Normalize a version string. Throws on invalid input.
     */
    public static function parse(string $version): string
    {
        $version = trim($version);

        if (! self::isValid($version)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid version string: "%s". Expected format: "v1", "2", "v2.1", or "2024-01-15".', $version)
            );
        }

        // Date-based versions are already in canonical form.
        if (self::isDate($version)) {
            return $version;
        }

        // Numeric: strip any v/V prefix, re-add lowercase "v".
        $normalized = ltrim($version, 'vV');

        return 'v' . strtolower($normalized);
    }

    /** Returns <0, 0, or >0 like strcmp. */
    public static function compare(string $versionA, string $versionB): int
    {
        $a = self::parse($versionA);
        $b = self::parse($versionB);

        return version_compare(
            self::toComparable($a),
            self::toComparable($b)
        );
    }

    /** Extract major version number. Throws on date-based versions. */
    public static function extractNumber(string $version): int
    {
        $normalized = self::parse($version);

        if (self::isDate($normalized)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot extract major number from date-based version: "%s".', $normalized)
            );
        }

        preg_match(self::NUMERIC_PATTERN, $normalized, $matches);

        return (int) $matches[1];
    }

    public static function isValid(string $version): bool
    {
        $version = trim($version);

        if ((bool) preg_match(self::NUMERIC_PATTERN, $version)) {
            return true;
        }

        return self::isDate($version);
    }

    /** Checks YYYY-MM-DD format with actual calendar validation. */
    public static function isDate(string $version): bool
    {
        $version = trim($version);

        if (! preg_match(self::DATE_PATTERN, $version)) {
            return false;
        }

        $parts = explode('-', $version);

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private static function toComparable(string $parsed): string
    {
        $result = ltrim($parsed, 'v');

        return str_replace('-', '.', $result);
    }
}
