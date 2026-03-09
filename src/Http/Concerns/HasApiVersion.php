<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Http\Concerns;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Registers Request macros for accessing the negotiated API version.
 *
 * Adds apiVersion(), isApiVersion(), isApiVersionAtLeast(),
 * and isApiVersionBefore() to every Request instance.
 */
final class HasApiVersion
{
    private function __construct()
    {
    }

    public static function register(): void
    {
        self::registerApiVersion();
        self::registerIsApiVersion();
        self::registerIsApiVersionAtLeast();
        self::registerIsApiVersionBefore();
    }

    private static function registerApiVersion(): void
    {
        Request::macro('apiVersion', function (string $fallback = 'v1'): string {
            /** @var Request $this */
            $version = $this->attributes->get('api_version');

            if ($version === null || ! is_string($version)) {
                return VersionParser::parse($fallback);
            }

            return $version;
        });
    }

    private static function registerIsApiVersion(): void
    {
        Request::macro('isApiVersion', function (string $version): bool {
            /** @var Request $this */
            $current = $this->attributes->get('api_version');

            if ($current === null || ! is_string($current)) {
                return false;
            }

            return VersionParser::compare($current, $version) === 0;
        });
    }

    private static function registerIsApiVersionAtLeast(): void
    {
        Request::macro('isApiVersionAtLeast', function (string $version): bool {
            /** @var Request $this */
            $current = $this->attributes->get('api_version');

            if ($current === null || ! is_string($current)) {
                return false;
            }

            return VersionParser::compare($current, $version) >= 0;
        });
    }

    private static function registerIsApiVersionBefore(): void
    {
        Request::macro('isApiVersionBefore', function (string $version): bool {
            /** @var Request $this */
            $current = $this->attributes->get('api_version');

            if ($current === null || ! is_string($current)) {
                return false;
            }

            return VersionParser::compare($current, $version) < 0;
        });
    }
}
