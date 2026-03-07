<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Support;

/**
 * Utility class for parsing, normalizing, and comparing API version strings.
 *
 * Handles various formats clients may send:
 *   - "2"    → "v2"
 *   - "V2"   → "v2"
 *   - "v2"   → "v2"
 *   - "2.1"  → "v2.1"
 *   - "v2.1" → "v2.1"
 *
 * All public methods are static — this class is not meant to be instantiated.
 */
final class VersionParser
{
    /**
     * Regex pattern matching a valid version string.
     *
     * Accepts an optional "v"/"V" prefix followed by a major version number
     * and an optional dot-separated minor version number.
     */
    private const VERSION_PATTERN = '/^[vV]?(\d+)(\.\d+)?$/';

    /**
     * Prevent instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Parse and normalize a version string to the canonical "vX" or "vX.Y" format.
     *
     * Examples:
     *   - parse("2")    → "v2"
     *   - parse("V2")   → "v2"
     *   - parse("v2")   → "v2"
     *   - parse("2.1")  → "v2.1"
     *   - parse("v2.1") → "v2.1"
     *
     * @param  string  $version  The raw version string to normalize.
     * @return string            The normalized version string (always lowercase "v" prefix).
     *
     * @throws \InvalidArgumentException If the version string is not valid.
     */
    public static function parse(string $version): string
    {
        $version = trim($version);

        if (! self::isValid($version)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid version string: "%s". Expected format: "v1", "2", "v2.1", etc.', $version)
            );
        }

        // Strip any existing v/V prefix, then re-add lowercase "v".
        $normalized = ltrim($version, 'vV');

        return 'v' . strtolower($normalized);
    }

    /**
     * Compare two version strings.
     *
     * Returns a negative integer, zero, or positive integer when the first
     * version is respectively less than, equal to, or greater than the second.
     *
     * Both values are normalized before comparison.
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

        // Use version_compare which handles "v" prefix and dot-separated segments.
        return version_compare(
            ltrim($a, 'v'),
            ltrim($b, 'v')
        );
    }

    /**
     * Extract the major version number from a version string.
     *
     * Examples:
     *   - extractNumber("v2")   → 2
     *   - extractNumber("v2.1") → 2
     *   - extractNumber("3")    → 3
     *
     * @param  string  $version  The version string to extract from.
     * @return int               The major version number.
     *
     * @throws \InvalidArgumentException If the version string is not valid.
     */
    public static function extractNumber(string $version): int
    {
        $normalized = self::parse($version);

        preg_match(self::VERSION_PATTERN, $normalized, $matches);

        return (int) $matches[1];
    }

    /**
     * Check whether a given string is a valid version identifier.
     *
     * A valid version string has an optional "v"/"V" prefix, followed by
     * one or more digits, optionally followed by a dot and more digits.
     *
     * @param  string  $version  The version string to validate.
     * @return bool              True if valid, false otherwise.
     */
    public static function isValid(string $version): bool
    {
        return (bool) preg_match(self::VERSION_PATTERN, trim($version));
    }
}
