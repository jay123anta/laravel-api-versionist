# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-03-09

### Added

- **Date-based versioning** — `VersionParser` now accepts ISO date versions like `2024-01-15` alongside numeric `v1`, `v2.1` formats. Dates are compared chronologically.
- **Laravel 12 support** — Updated all `illuminate/*` dependencies to `^10.0|^11.0|^12.0` and `orchestra/testbench` to `^8.0|^9.0|^10.0`.
- **Pipeline error wrapping** — `RequestUpgradePipeline` and `ResponseDowngradePipeline` now catch transformer exceptions and re-throw with the transformer class name for easier debugging.
- **Version string sanitization** — `VersionDetector` strips control characters and enforces a max length on detected version strings before parsing.

### Changed

- `MakeTransformerCommand` now handles date-based version strings when generating class names.
- `TransformerRegistry::baselineVersion()` derives the baseline as one day prior for date-based versions.

## [1.0.0] - 2024-09-01

### Added

- **Core Engine**
  - `TransformerRegistry` for managing version transformers with automatic sort ordering
  - `RequestUpgradePipeline` to transform incoming request payloads from older to newer versions
  - `ResponseDowngradePipeline` to transform outgoing response payloads from newer to older versions
  - Implicit baseline version derivation (one major below the lowest registered transformer)

- **Version Detection**
  - `VersionDetector` with 4 pluggable strategies: URL prefix, custom header, Accept header, query parameter
  - `VersionNegotiator` with strict mode (throw on unknown) and fallback mode (default version)
  - Configurable strategy priority ordering

- **HTTP Layer**
  - `ApiVersionMiddleware` for automatic request upgrade and response downgrade
  - `ApiVersionistManager` central orchestrator with envelope support
  - Request macros: `apiVersion()`, `isApiVersion()`, `isApiVersionAtLeast()`, `isApiVersionBefore()`
  - `Route::versioned()` macro for route registration shorthand

- **RFC 8594 Deprecation Support**
  - Automatic `Deprecation: true` header for deprecated versions
  - `Sunset` header with configurable sunset dates per version
  - `Link` header with `rel="successor-version"` pointing to changelog endpoint

- **Response Headers**
  - `X-Api-Version` echoes the client's resolved version
  - `X-Api-Latest-Version` advertises the current latest version

- **Events**
  - `RequestUpgraded` event dispatched after request payload transformation
  - `ResponseDowngraded` event dispatched after response payload transformation

- **Artisan Commands**
  - `api:make-transformer` scaffolds a new transformer from a customizable stub
  - `api:versions` lists all registered versions with status and transformer info
  - `api:changelog` displays a formatted changelog (table, JSON, or Markdown output)
  - `api:audit` validates all registered transformers and dry-runs the full pipeline

- **Developer Experience**
  - `ApiVersionTransformer` abstract base class with sensible defaults
  - `VersionParser` utility for normalizing and comparing version strings
  - `ApiVersionist` facade proxying the manager
  - Publishable config (`api-versionist-config`) and stubs (`api-versionist-stubs`)
  - Laravel auto-discovery via `composer.json` extras

- **Envelope Support**
  - `response_data_key` config to transform only nested data (e.g., `"data"` in `{ "data": {...}, "meta": {...} }`)
  - `request_data_key` config for enveloped request payloads
  - Non-data keys (meta, links, pagination) pass through untouched

[1.1.0]: https://github.com/jay123anta/laravel-api-versionist/releases/tag/v1.1.0
[1.0.0]: https://github.com/jay123anta/laravel-api-versionist/releases/tag/v1.0.0
