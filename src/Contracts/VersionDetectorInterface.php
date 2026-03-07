<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Contracts;

use Illuminate\Http\Request;

/**
 * Contract for detecting the requested API version from an HTTP request.
 *
 * Implementations inspect the incoming request (headers, URL segments,
 * query parameters, etc.) and return the normalized version string
 * requested by the client, or null if no version could be determined.
 *
 * Multiple detectors can be chained together to support different
 * versioning strategies simultaneously (header, URL prefix, query param).
 */
interface VersionDetectorInterface
{
    /**
     * Detect the requested API version from the given HTTP request.
     *
     * Inspect the request for version information and return the raw
     * version string as provided by the client. The caller is responsible
     * for normalizing the returned value via {@see VersionParser::parse()}.
     *
     * @param  Request  $request  The incoming HTTP request instance.
     * @return string|null        The detected version string, or null if none found.
     */
    public function detect(Request $request): ?string;
}
