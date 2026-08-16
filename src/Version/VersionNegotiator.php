<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Version;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Exceptions\UnknownVersionException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Resolves the effective API version for a request.
 *
 * Detects version from request, validates against registry, falls back
 * to default_version. In strict mode, unknown versions throw.
 * Also handles RFC 8594 deprecation headers.
 */
final class VersionNegotiator
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly VersionDetector $detector, private readonly TransformerRegistry $registry, private readonly array $config) {}

    /**
     * @throws UnknownVersionException in strict mode for unknown versions
     */
    public function negotiate(Request $request): string
    {
        $detected = $this->detector->detect($request);

        if ($detected === null) {
            return VersionParser::parse($this->defaultVersion());
        }

        if ($this->registry->isKnownVersion($detected)) {
            return VersionParser::parse($detected);
        }

        if ($this->isStrictMode()) {
            throw UnknownVersionException::forVersion(
                $detected,
                $this->registry->getVersions(),
            );
        }

        return VersionParser::parse($this->defaultVersion());
    }

    public function isDeprecated(string $version): bool
    {
        return $this->deprecationEntry(VersionParser::parse($version)) !== null;
    }

    public function getSunsetDate(string $version): ?string
    {
        return $this->entryDate($this->deprecationEntry(VersionParser::parse($version)), 'sunset');
    }

    public function getDeprecatedAtDate(string $version): ?string
    {
        return $this->entryDate($this->deprecationEntry(VersionParser::parse($version)), 'deprecated_at');
    }

    /**
     * The effective latest version: the configured latest_version override
     * when valid, otherwise the highest registered transformer version.
     */
    public function latestVersion(): string
    {
        $configured = $this->config['latest_version'] ?? null;

        if (is_string($configured) && VersionParser::isValid($configured)) {
            return VersionParser::parse($configured);
        }

        return $this->registry->latestVersion();
    }

    /** @return array<string, string> Version + deprecation headers per RFC 8594 / RFC 9745 */
    public function buildDeprecationHeaders(string $version, string $latest): array
    {
        $normalized       = VersionParser::parse($version);
        $normalizedLatest = VersionParser::parse($latest);

        $headers = [
            'X-Api-Version'        => $normalized,
            'X-Api-Latest-Version' => $normalizedLatest,
        ];

        $entry = $this->deprecationEntry($normalized);

        if ($entry !== null) {
            $headers['Deprecation'] = $this->deprecationHeaderValue($entry);

            $sunset = $this->entryDate($entry, 'sunset');
            if ($sunset !== null) {
                $headers['Sunset'] = $this->useRfcCompliantHeaders()
                    ? $this->toHttpDate($sunset)
                    : $sunset;
            }
        }

        return $headers;
    }

    /**
     * Normalizes a deprecated_versions entry: a plain value is treated as the
     * sunset date; the array form may carry "sunset" and "deprecated_at" keys.
     * Returns null when the version is not deprecated.
     *
     * @return array<string, mixed>|null
     */
    private function deprecationEntry(string $normalizedVersion): ?array
    {
        /** @var array<string, mixed> $deprecated */
        $deprecated = $this->config['deprecated_versions'] ?? [];

        if (! array_key_exists($normalizedVersion, $deprecated)) {
            return null;
        }

        $entry = $deprecated[$normalizedVersion];

        return is_array($entry) ? $entry : ['sunset' => $entry];
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private function entryDate(?array $entry, string $key): ?string
    {
        $date = $entry[$key] ?? null;

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * RFC 9745 wants Deprecation as an @-prefixed unix timestamp; the legacy
     * draft boolean "true" remains the default and the fallback when no
     * deprecated_at date is configured.
     *
     * @param  array<string, mixed>  $entry
     */
    private function deprecationHeaderValue(array $entry): string
    {
        if (! $this->useRfcCompliantHeaders()) {
            return 'true';
        }

        $deprecatedAt = $this->entryDate($entry, 'deprecated_at');

        if ($deprecatedAt === null) {
            return 'true';
        }

        try {
            $timestamp = (new \DateTimeImmutable($deprecatedAt, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return 'true';
        }

        return '@' . $timestamp;
    }

    /** RFC 8594 requires Sunset to be an HTTP-date (IMF-fixdate) in GMT. */
    private function toHttpDate(string $date): string
    {
        $utc = new \DateTimeZone('UTC');

        try {
            return (new \DateTimeImmutable($date, $utc))
                ->setTimezone($utc)
                ->format(\DateTimeInterface::RFC7231);
        } catch (\Exception) {
            return $date;
        }
    }

    private function useRfcCompliantHeaders(): bool
    {
        return (bool) ($this->config['rfc_compliant_headers'] ?? false);
    }

    private function defaultVersion(): string
    {
        return $this->config['default_version'] ?? 'v1';
    }

    private function isStrictMode(): bool
    {
        return (bool) ($this->config['strict_mode'] ?? false);
    }
}
