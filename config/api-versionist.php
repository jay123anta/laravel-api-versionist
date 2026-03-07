<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default API Version
    |--------------------------------------------------------------------------
    |
    | The version to assume when no version can be detected from the incoming
    | request. In non-strict mode, this is also the fallback when a client
    | requests an unregistered version.
    |
    | Must be a valid version string recognized by VersionParser (e.g. "v1").
    |
    */

    'default_version' => 'v1',

    /*
    |--------------------------------------------------------------------------
    | Latest API Version
    |--------------------------------------------------------------------------
    |
    | The current/latest version your API is serving. This is used by the
    | negotiator to build deprecation headers and to determine the upgrade
    | target when transforming incoming requests.
    |
    | Should match the highest transformer version in the registry.
    |
    */

    'latest_version' => 'v1',

    /*
    |--------------------------------------------------------------------------
    | Deprecated Versions
    |--------------------------------------------------------------------------
    |
    | A map of version strings to their sunset dates (ISO-8601 format).
    | Deprecated versions still function but emit Deprecation and Sunset
    | headers in the response to signal clients they should migrate.
    |
    | Set the value to null if no sunset date has been determined yet.
    |
    | Example:
    |   'v1' => '2025-06-01',
    |   'v2' => null,
    |
    */

    'deprecated_versions' => [
        // 'v1' => '2025-06-01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Detection Strategies
    |--------------------------------------------------------------------------
    |
    | Ordered list of strategies the VersionDetector will try when extracting
    | the API version from an incoming request. The first strategy that
    | returns a non-null value wins.
    |
    | Supported strategies:
    |   - "url_prefix"     : Extract from URL path  (e.g. /api/v2/users)
    |   - "header"         : Read a custom header   (e.g. X-Api-Version: v2)
    |   - "accept_header"  : Parse Accept header    (e.g. application/vnd.api+json;version=2)
    |   - "query_param"    : Read a query parameter (e.g. ?version=v2)
    |
    */

    'detection_strategies' => [
        'url_prefix',
        'header',
        'accept_header',
        'query_param',
    ],

    /*
    |--------------------------------------------------------------------------
    | Header Name (for "header" strategy)
    |--------------------------------------------------------------------------
    |
    | The HTTP request header to inspect when using the "header" detection
    | strategy. Case-insensitive as per HTTP spec.
    |
    */

    'header_name' => 'X-Api-Version',

    /*
    |--------------------------------------------------------------------------
    | Accept Header Pattern (for "accept_header" strategy)
    |--------------------------------------------------------------------------
    |
    | A regex pattern used to extract the version from the Accept header.
    | The pattern MUST contain a named capture group called "version".
    |
    | Default pattern matches: application/vnd.api+json;version=2
    |
    */

    'accept_header_pattern' => '/application\/vnd\.[a-zA-Z0-9.-]+\+json;\s*version=(?P<version>[vV]?\d+(?:\.\d+)?)/',

    /*
    |--------------------------------------------------------------------------
    | Query Parameter Name (for "query_param" strategy)
    |--------------------------------------------------------------------------
    |
    | The query string parameter to inspect when using the "query_param"
    | detection strategy.
    |
    */

    'query_param' => 'version',

    /*
    |--------------------------------------------------------------------------
    | URL Prefix Pattern (for "url_prefix" strategy)
    |--------------------------------------------------------------------------
    |
    | A regex pattern used to extract the version from the URL path.
    | The pattern MUST contain a named capture group called "version".
    |
    | Default pattern matches: /api/v2/..., /v3/..., /api/v2.1/...
    |
    */

    'url_prefix_pattern' => '/\/(?:api\/)?(?P<version>[vV]\d+(?:\.\d+)?)(?:\/|$)/',

    /*
    |--------------------------------------------------------------------------
    | Transformers
    |--------------------------------------------------------------------------
    |
    | An array of fully-qualified class names for your version transformers.
    | Each class must implement VersionTransformerInterface or extend the
    | ApiVersionTransformer abstract base class.
    |
    | These are registered into the TransformerRegistry by the service
    | provider at boot time.
    |
    | Example:
    |   App\ApiTransformers\V2Transformer::class,
    |   App\ApiTransformers\V3Transformer::class,
    |
    */

    'transformers' => [
        // App\ApiTransformers\V2Transformer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Data Key
    |--------------------------------------------------------------------------
    |
    | The key in the JSON response that contains the data array to be
    | downgraded through the pipeline. Set to null to transform the
    | entire response body.
    |
    | Common values: "data", "result", null (entire body)
    |
    */

    'response_data_key' => 'data',

    /*
    |--------------------------------------------------------------------------
    | Request Data Key
    |--------------------------------------------------------------------------
    |
    | The key in the JSON request body that contains the data array to be
    | upgraded through the pipeline. Set to null to transform the entire
    | request body.
    |
    | Common values: "data", null (entire body)
    |
    */

    'request_data_key' => null,

    /*
    |--------------------------------------------------------------------------
    | Add Version Headers to Response
    |--------------------------------------------------------------------------
    |
    | When enabled, the middleware will automatically add informational
    | headers to every API response:
    |
    |   X-Api-Version:        The version the client requested
    |   X-Api-Latest-Version: The latest version available
    |   Deprecation:          RFC 8594 header (if version is deprecated)
    |   Sunset:               RFC 8594 sunset date (if set)
    |
    */

    'add_version_headers' => true,

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | When strict mode is enabled, the VersionNegotiator will throw an
    | UnknownVersionException if a client requests a version that is not
    | registered in the TransformerRegistry.
    |
    | When disabled, unrecognized versions silently fall back to the
    | default_version value defined above.
    |
    */

    'strict_mode' => false,

    /*
    |--------------------------------------------------------------------------
    | Changelog Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the optional changelog endpoint that exposes
    | registered API versions and their metadata.
    |
    |   enabled  : Whether to register the changelog route.
    |   endpoint : The URI path for the changelog endpoint.
    |   middleware: Middleware to apply to the changelog route.
    |
    */

    'changelog' => [
        'enabled'    => false,
        'endpoint'   => '/api/versions',
        'middleware'  => ['api'],
    ],

];
