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
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(
        private readonly VersionDetector $detector,
        private readonly TransformerRegistry $registry,
        array $config,
    ) {
        $this->config = $config;
    }

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
        $normalized = VersionParser::parse($version);

        /** @var array<string, string|null> $deprecated */
        $deprecated = $this->config['deprecated_versions'] ?? [];

        return array_key_exists($normalized, $deprecated);
    }

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

    /** @return array<string, string> Version + deprecation headers per RFC 8594 */
    public function buildDeprecationHeaders(string $version, string $latest): array
    {
        $normalized      = VersionParser::parse($version);
        $normalizedLatest = VersionParser::parse($latest);

        $headers = [
            'X-Api-Version'        => $normalized,
            'X-Api-Latest-Version' => $normalizedLatest,
        ];

        if ($this->isDeprecated($normalized)) {
            $headers['Deprecation'] = 'true';

            $sunset = $this->getSunsetDate($normalized);
            if ($sunset !== null) {
                $headers['Sunset'] = $sunset;
            }
        }

        return $headers;
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
