<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Version;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Exceptions\UnknownVersionException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Negotiates the effective API version for an incoming request.
 *
 * Combines the VersionDetector (which extracts the raw version from the
 * request) with the TransformerRegistry (which knows valid versions) and
 * the package configuration (which defines defaults and strict mode).
 *
 * ## Resolution flow
 *
 * ```
 * Request
 *   │
 *   ▼
 * VersionDetector::detect()  ──▶  raw version string (or null)
 *   │
 *   ▼
 * Null? ──yes──▶ use default_version from config
 *   │
 *   no
 *   │
 *   ▼
 * Registered in registry?
 *   │         │
 *  yes        no
 *   │         │
 *   │     strict_mode?
 *   │      │        │
 *   │     yes       no
 *   │      │        │
 *   │  THROW    use default_version
 *   │
 *   ▼
 * Return normalized version
 * ```
 *
 * The negotiator also provides deprecation introspection: checking whether
 * a version is deprecated, retrieving its sunset date, and building the
 * standard RFC 8594 deprecation response headers.
 */
final class VersionNegotiator
{
    /**
     * The resolved package configuration array.
     *
     * @var array<string, mixed>
     */
    private readonly array $config;

    /**
     * Create a new VersionNegotiator instance.
     *
     * @param  VersionDetector       $detector  The version detection engine.
     * @param  TransformerRegistry   $registry  The transformer registry for version validation.
     * @param  array<string, mixed>  $config    The api-versionist configuration array.
     */
    public function __construct(
        private readonly VersionDetector $detector,
        private readonly TransformerRegistry $registry,
        array $config,
    ) {
        $this->config = $config;
    }

    /**
     * Negotiate the effective API version for the given request.
     *
     * 1. Attempt to detect a version from the request via the detector.
     * 2. If nothing detected, fall back to `default_version` from config.
     * 3. If detected but not registered in the registry:
     *    - strict_mode=true  → throw UnknownVersionException
     *    - strict_mode=false → fall back to `default_version`
     * 4. Return the normalized, validated version string.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string             The normalized, resolved API version string.
     *
     * @throws UnknownVersionException When strict mode is enabled and the
     *                                 detected version is not registered.
     */
    public function negotiate(Request $request): string
    {
        $detected = $this->detector->detect($request);

        // ── Nothing detected → use the configured default ──
        if ($detected === null) {
            return VersionParser::parse($this->defaultVersion());
        }

        // ── Detected a version — validate against the registry ──
        if ($this->registry->isKnownVersion($detected)) {
            return VersionParser::parse($detected);
        }

        // ── Unknown version — behavior depends on strict mode ──
        if ($this->isStrictMode()) {
            throw UnknownVersionException::forVersion(
                $detected,
                $this->registry->getVersions(),
            );
        }

        // Non-strict: silently fall back to default.
        return VersionParser::parse($this->defaultVersion());
    }

    /**
     * Check whether a version is deprecated.
     *
     * A version is deprecated if it appears as a key in the
     * `deprecated_versions` config map — regardless of whether a
     * sunset date has been set.
     *
     * @param  string  $version  The version string to check.
     * @return bool              True if the version is deprecated.
     */
    public function isDeprecated(string $version): bool
    {
        $normalized = VersionParser::parse($version);

        /** @var array<string, string|null> $deprecated */
        $deprecated = $this->config['deprecated_versions'] ?? [];

        return array_key_exists($normalized, $deprecated);
    }

    /**
     * Retrieve the sunset date for a deprecated version.
     *
     * Returns the ISO-8601 date string from the `deprecated_versions`
     * config map, or null if no sunset date is set or the version is
     * not deprecated.
     *
     * @param  string  $version  The version string to look up.
     * @return string|null       The ISO-8601 sunset date, or null.
     */
    public function getSunsetDate(string $version): ?string
    {
        $normalized = VersionParser::parse($version);

        /** @var array<string, string|null> $deprecated */
        $deprecated = $this->config['deprecated_versions'] ?? [];

        if (! array_key_exists($normalized, $deprecated)) {
            return null;
        }

        $date = $deprecated[$normalized];

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * Build RFC 8594 deprecation and informational response headers.
     *
     * Returns an associative array of header name → value pairs that
     * should be added to the API response. Always includes version
     * identification headers; conditionally adds Deprecation and Sunset
     * headers when the resolved version is deprecated.
     *
     * ## Headers produced
     *
     * | Header               | When                    | Example                |
     * |----------------------|-------------------------|------------------------|
     * | X-Api-Version        | Always                  | v1                     |
     * | X-Api-Latest-Version | Always                  | v3                     |
     * | Deprecation          | Version is deprecated   | true                   |
     * | Sunset               | Sunset date is set      | 2025-06-01             |
     *
     * @param  string  $version  The resolved client version.
     * @param  string  $latest   The latest available version.
     * @return array<string, string>  Header name → value pairs.
     */
    public function buildDeprecationHeaders(string $version, string $latest): array
    {
        $normalized      = VersionParser::parse($version);
        $normalizedLatest = VersionParser::parse($latest);

        $headers = [
            'X-Api-Version'        => $normalized,
            'X-Api-Latest-Version' => $normalizedLatest,
        ];

        // Add deprecation headers only when the version is explicitly
        // listed in the deprecated_versions config.
        if ($this->isDeprecated($normalized)) {
            // RFC 8594 §2: The "Deprecation" header field value is either
            // a date or the boolean "true".
            $headers['Deprecation'] = 'true';

            $sunset = $this->getSunsetDate($normalized);

            if ($sunset !== null) {
                // RFC 8594 §3: Sunset header indicates the removal date.
                $headers['Sunset'] = $sunset;
            }
        }

        return $headers;
    }

    /**
     * Return the configured default version string.
     *
     * @return string  The raw default version from config.
     */
    private function defaultVersion(): string
    {
        return $this->config['default_version'] ?? 'v1';
    }

    /**
     * Check whether strict mode is enabled in the configuration.
     *
     * @return bool  True if strict mode is on.
     */
    private function isStrictMode(): bool
    {
        return (bool) ($this->config['strict_mode'] ?? false);
    }
}
