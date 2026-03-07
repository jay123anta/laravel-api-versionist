<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Version;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Contracts\VersionDetectorInterface;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Detects the requested API version from an incoming HTTP request.
 *
 * Iterates through an ordered list of detection strategies defined in
 * the config. The first strategy that extracts a valid, non-null version
 * string wins. All detected values are normalized through VersionParser.
 *
 * ## Supported strategies (tried in config-defined order):
 *
 * | Strategy        | Example                                            |
 * |-----------------|----------------------------------------------------|
 * | `url_prefix`    | `GET /api/v2/users`                                |
 * | `header`        | `X-Api-Version: v2`                                |
 * | `accept_header` | `Accept: application/vnd.api+json;version=2`       |
 * | `query_param`   | `GET /api/users?version=v2`                        |
 *
 * Each strategy is a private method, making it straightforward to add
 * new strategies by adding a method and a case to the dispatch map.
 */
final class VersionDetector implements VersionDetectorInterface
{
    /**
     * Map of strategy names to their corresponding private method names.
     *
     * Used to dispatch detection calls without a long if/elseif chain.
     * Adding a new strategy is as simple as adding an entry here and
     * implementing the matching private method.
     *
     * @var array<string, string>
     */
    private const STRATEGY_METHOD_MAP = [
        'url_prefix'    => 'detectFromUrlPrefix',
        'header'        => 'detectFromHeader',
        'accept_header' => 'detectFromAcceptHeader',
        'query_param'   => 'detectFromQueryParam',
    ];

    /**
     * The resolved package configuration array.
     *
     * @var array<string, mixed>
     */
    private readonly array $config;

    /**
     * Create a new VersionDetector instance.
     *
     * Receives the raw config array — not a facade or repository — so
     * this class remains testable without a running Laravel application.
     *
     * @param  array<string, mixed>  $config  The api-versionist configuration array.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Detect the requested API version from the given HTTP request.
     *
     * Iterates through the configured detection strategies in order.
     * The first strategy to return a non-null, parseable version string
     * wins. The returned value is always normalized via VersionParser.
     *
     * Returns null only when every strategy fails to detect a version.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null        The normalized version string, or null if undetected.
     */
    public function detect(Request $request): ?string
    {
        /** @var array<int, string> $strategies */
        $strategies = $this->config['detection_strategies'] ?? [];

        foreach ($strategies as $strategy) {
            // Look up the method name for this strategy. Skip unknown
            // strategy names silently — allows forward-compatible configs.
            $method = self::STRATEGY_METHOD_MAP[$strategy] ?? null;

            if ($method === null) {
                continue;
            }

            $detected = $this->{$method}($request);

            if ($detected === null) {
                continue;
            }

            // Normalize the detected value. If it's not a valid version
            // string (e.g. user sent garbage), skip it and try the next
            // strategy rather than blowing up.
            if (! VersionParser::isValid($detected)) {
                continue;
            }

            return VersionParser::parse($detected);
        }

        // No strategy produced a valid version.
        return null;
    }

    /**
     * Extract version from the URL path prefix.
     *
     * Matches patterns like `/api/v2/users`, `/v3/resource`, `/api/v2.1/`.
     * Uses the configurable `url_prefix_pattern` regex.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null        The raw version string from the URL, or null.
     */
    private function detectFromUrlPrefix(Request $request): ?string
    {
        $pattern = $this->config['url_prefix_pattern'] ?? '';

        if ($pattern === '') {
            return null;
        }

        $path = '/' . ltrim($request->path(), '/');

        if (preg_match($pattern, $path, $matches) && isset($matches['version'])) {
            return $matches['version'];
        }

        return null;
    }

    /**
     * Extract version from a custom HTTP request header.
     *
     * Reads the header specified by the `header_name` config key.
     * Example: `X-Api-Version: v2` → "v2"
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null        The raw header value, or null if absent/empty.
     */
    private function detectFromHeader(Request $request): ?string
    {
        $headerName = $this->config['header_name'] ?? 'X-Api-Version';

        $value = $request->header($headerName);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Extract version from the Accept header using a vendor media type pattern.
     *
     * Parses headers like: `Accept: application/vnd.myapi+json;version=2`
     * Uses the configurable `accept_header_pattern` regex which must define
     * a named capture group called "version".
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null        The raw version string from Accept, or null.
     */
    private function detectFromAcceptHeader(Request $request): ?string
    {
        $pattern = $this->config['accept_header_pattern'] ?? '';

        if ($pattern === '') {
            return null;
        }

        $accept = $request->header('Accept', '');

        if ($accept === '' || $accept === null) {
            return null;
        }

        if (preg_match($pattern, $accept, $matches) && isset($matches['version'])) {
            return $matches['version'];
        }

        return null;
    }

    /**
     * Extract version from a query string parameter.
     *
     * Reads the query parameter specified by the `query_param` config key.
     * Example: `?version=v2` → "v2"
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return string|null        The raw query parameter value, or null if absent/empty.
     */
    private function detectFromQueryParam(Request $request): ?string
    {
        $paramName = $this->config['query_param'] ?? 'version';

        $value = $request->query($paramName);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
