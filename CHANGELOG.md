# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Community health files: `CONTRIBUTING.md`, `SECURITY.md`, issue and PR templates.
- `.gitattributes` with `export-ignore` so the Packagist dist archive ships runtime files only.
- Codecov upload in CI with a coverage badge.
- Comparison table vs. alternative packages and a [migration guide from request-migrations](docs/migrating-from-request-migrations.md).
- Table-driven edge-case tests for `VersionParser` (valid/invalid/comparison datasets).

### Changed

- `minimum-stability` raised from `dev` to `stable`; added Packagist `support` links.

## [1.2.0] - 2026-08-16

### Added

- **Changelog endpoint** — enabling `changelog.enabled` now registers a real GET route (named `api-versionist.changelog`) serving the version changelog as JSON; previously the config only fed the `Link` header. Internal transformer class names are not exposed over HTTP.
- **`#[ApiVersion]` attribute** — transformers can declare version, description, and release date via a class attribute instead of overriding `version()`/`description()`/`releasedAt()`. Method overrides still win; `version()` throws a `LogicException` when neither is provided.
- **RFC-compliant deprecation headers (opt-in)** — new `rfc_compliant_headers` config emits `Sunset` as an RFC 8594 HTTP-date and `Deprecation` as an RFC 9745 `@`-prefixed unix timestamp. `deprecated_versions` entries now also accept an array form `['deprecated_at' => ..., 'sunset' => ...]`; the plain string form keeps working unchanged.
- **Laravel 13 support** — `illuminate/*` dependencies now allow `^13.0`, `orchestra/testbench` allows `^11.0`, and `phpunit/phpunit` allows `^12.0`. CI matrix extended to PHP 8.5 and Laravel 13.
- **Quality toolchain** — PHPStan (level 8), Laravel Pint, and Rector configs with a dedicated CI quality job; Dependabot for composer and GitHub Actions; a `--prefer-lowest` CI job verifying the dependency floor; a coverage report job.
- **Behavioral tests for the `Route::versioned()` macro** covering both the inline route form and the group/registrar form.

### Changed

- Declared directly-used dependencies explicitly: `illuminate/console`, `illuminate/filesystem`, and `symfony/http-foundation` (previously only available transitively).
- Feature tests now register routes through Testbench's `defineRoutes()` hook instead of `setUp()`.
- Codebase reformatted with Pint (Laravel preset) and modernized with Rector (constructor property promotion).
- Raised the `phpunit/phpunit` floor to `^10.1` (the `<source>` config element requires 10.1+).

### Fixed

- `api:changelog --format` and `api:make-transformer --path` no longer pass `null` to string functions when the option is given without a value (PHP 8.1+ deprecation, TypeError in PHP 9).
- `VersionDetector` header and Accept-header strategies now ignore non-string values instead of passing arrays to `trim()`/`preg_match()`.
- `api:audit --from/--to` options and the `api:make-transformer` version argument are type-guarded before use.
- `api:changelog --format=json` uses `JSON_THROW_ON_ERROR` instead of silently printing `false` on encoding failure.

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

[1.2.0]: https://github.com/jay123anta/laravel-api-versionist/releases/tag/v1.2.0
[1.1.0]: https://github.com/jay123anta/laravel-api-versionist/releases/tag/v1.1.0
[1.0.0]: https://github.com/jay123anta/laravel-api-versionist/releases/tag/v1.0.0
