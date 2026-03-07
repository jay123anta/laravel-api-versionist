<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Http\Concerns;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Registers Request macros that give controllers clean, expressive
 * access to the negotiated API version.
 *
 * Call {@see HasApiVersion::register()} once during the service provider
 * boot phase. After registration, every Request instance gains the
 * following methods:
 *
 * ```php
 * $request->apiVersion();              // "v2"
 * $request->isApiVersion('v2');        // true
 * $request->isApiVersionAtLeast('v2'); // true if >= v2
 * $request->isApiVersionBefore('v3');  // true if < v3
 * ```
 *
 * The macros read the `api_version` attribute that the
 * {@see ApiVersionMiddleware} sets during request handling.
 *
 * ## Usage in controllers
 *
 * ```php
 * public function index(Request $request)
 * {
 *     $users = User::query()->paginate();
 *
 *     // Include extra data only for v3+ clients:
 *     if ($request->isApiVersionAtLeast('v3')) {
 *         $users->load('profile');
 *     }
 *
 *     return UserResource::collection($users);
 * }
 * ```
 */
final class HasApiVersion
{
    /**
     * Prevent instantiation — this class is a static registrar only.
     */
    private function __construct()
    {
    }

    /**
     * Register all API version macros on the Request class.
     *
     * Safe to call multiple times — Laravel's macro system silently
     * overwrites existing macros with the same name.
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerApiVersion();
        self::registerIsApiVersion();
        self::registerIsApiVersionAtLeast();
        self::registerIsApiVersionBefore();
    }

    /**
     * Register the `apiVersion()` macro.
     *
     * Returns the negotiated API version string from the request
     * attributes, or the provided fallback (default "v1") if the
     * middleware has not yet run.
     *
     * @return void
     */
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

    /**
     * Register the `isApiVersion()` macro.
     *
     * Returns true if the request's negotiated API version matches
     * the given version exactly (after normalization).
     *
     * @return void
     */
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

    /**
     * Register the `isApiVersionAtLeast()` macro.
     *
     * Returns true if the request's negotiated API version is greater
     * than or equal to the given version (after normalization).
     *
     * @return void
     */
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

    /**
     * Register the `isApiVersionBefore()` macro.
     *
     * Returns true if the request's negotiated API version is strictly
     * less than the given version (after normalization).
     *
     * @return void
     */
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
