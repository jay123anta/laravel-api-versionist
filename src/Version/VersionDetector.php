<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Version;

use Illuminate\Http\Request;
use Versionist\ApiVersionist\Contracts\VersionDetectorInterface;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Detects the API version from an HTTP request using configurable strategies
 * (url_prefix, header, accept_header, query_param). First match wins.
 */
final class VersionDetector implements VersionDetectorInterface
{
    private const STRATEGY_METHOD_MAP = [
        'url_prefix'    => 'detectFromUrlPrefix',
        'header'        => 'detectFromHeader',
        'accept_header' => 'detectFromAcceptHeader',
        'query_param'   => 'detectFromQueryParam',
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function detect(Request $request): ?string
    {
        $strategies = $this->config['detection_strategies'] ?? [];

        foreach ($strategies as $strategy) {
            $method = self::STRATEGY_METHOD_MAP[$strategy] ?? null;

            if ($method === null) {
                continue;
            }

            $detected = $this->{$method}($request);

            if ($detected === null) {
                continue;
            }

            $detected = self::sanitize($detected);

            if (! VersionParser::isValid($detected)) {
                continue;
            }

            return VersionParser::parse($detected);
        }

        return null;
    }

    /** Strip control chars and cap length. */
    private static function sanitize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;

        return substr(trim($value), 0, 32);
    }

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

    private function detectFromHeader(Request $request): ?string
    {
        $headerName = $this->config['header_name'] ?? 'X-Api-Version';

        $value = $request->header($headerName);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

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
