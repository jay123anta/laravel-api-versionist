<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Support;

/**
 * Utility class for parsing, normalizing, and comparing API version strings.
 *
 * Handles various formats clients may send:
 *   - "2"          → "v2"
 *   - "V2"         → "v2"
 *   - "v2"         → "v2"
 *   - "2.1"        → "v2.1"
 *   - "v2.1"       → "v2.1"
 *   - "2024-01-15" → "2024-01-15"  (date-based)
 *
 * All public methods are static — this class is not meant to be instantiated.
 */
final class VersionParser
{
    /**
     * Regex matching a numeric version string (v1, v2.1, etc.).
     */
    private const NUMERIC_PATTERN = '/^[vV]?(\d+)(\.\d+)?$/';

    /**
     * Regex matching a date-based version string (2024-01-15, etc.).
     */
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private function __construct()
    {
    }

    /**
     * Parse and normalize a version string.
     *
     * Numeric versions are normalized to "vX" or "vX.Y" format.
     * Date-based versions are returned as-is (YYYY-MM-DD).
     *
     * @param  string  $version  The raw version string to normalize.
     * @return string            The normalized version string.
     *
     * @throws \InvalidArgumentException If the version string is not valid.
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

    /**
     * Compare two version strings.
     *
     * Returns a negative integer, zero, or positive integer when the first
     * version is respectively less than, equal to, or greater than the second.
     *
     * Both numeric and date-based versions are supported. Date versions
     * are converted to dot-separated segments for comparison via version_compare().
     *
     * @param  string  $versionA  The first version string.
     * @param  string  $versionB  The second version string.
     * @return int                < 0 if A < B, 0 if A == B, > 0 if A > B.
     *
     * @throws \InvalidArgumentException If either version string is invalid.
     */
    public static function compare(string $versionA, string $versionB): int
    {
        $a = self::parse($versionA);
        $b = self::parse($versionB);

        // Normalize both to dot-separated numeric strings for version_compare().
        // "v2.1" → "2.1", "2024-01-15" → "2024.01.15"
        return version_compare(
            self::toComparable($a),
            self::toComparable($b)
        );
    }

    /**
     * Extract the major version number from a numeric version string.
     *
     * Not supported for date-based versions — use isDate() to check first.
     *
     * @param  string  $version  The version string to extract from.
     * @return int               The major version number.
     *
     * @throws \InvalidArgumentException If the version string is not valid or is date-based.
     */
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

    /**
     * Check whether a given string is a valid version identifier.
     *
     * Accepts numeric versions (v1, 2, v2.1) and date-based versions (2024-01-15).
     *
     * @param  string  $version  The version string to validate.
     * @return bool              True if valid, false otherwise.
     */
    public static function isValid(string $version): bool
    {
        $version = trim($version);

        if ((bool) preg_match(self::NUMERIC_PATTERN, $version)) {
            return true;
        }

        return self::isDate($version);
    }

    /**
     * Check whether a version string is in date format (YYYY-MM-DD).
     *
     * Validates both the format and the actual date (rejects 2024-13-45).
     *
     * @param  string  $version  The version string to check.
     * @return bool              True if date-based, false otherwise.
     */
    public static function isDate(string $version): bool
    {
        $version = trim($version);

        if (! preg_match(self::DATE_PATTERN, $version)) {
            return false;
        }

        // Validate actual date — reject things like 2024-13-45.
        $parts = explode('-', $version);

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    /**
     * Convert a parsed version string to a dot-separated format for version_compare().
     *
     * "v2.1" → "2.1", "2024-01-15" → "2024.01.15"
     */
    private static function toComparable(string $parsed): string
    {
        // Strip v prefix for numeric versions.
        $result = ltrim($parsed, 'v');

        // Convert date dashes to dots so version_compare handles them correctly.
        return str_replace('-', '.', $result);
    }
}
