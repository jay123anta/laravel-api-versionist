<p align="center">
  <strong>Laravel API Versionist</strong><br>
  Elegant API versioning for Laravel — transform requests and responses across versions automatically.
</p>

<p align="center">
  <a href="https://packagist.org/packages/versionist/laravel-api-versionist"><img src="https://img.shields.io/packagist/v/versionist/laravel-api-versionist.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="https://packagist.org/packages/versionist/laravel-api-versionist"><img src="https://img.shields.io/packagist/l/versionist/laravel-api-versionist.svg?style=flat-square" alt="License"></a>
  <a href="https://packagist.org/packages/versionist/laravel-api-versionist"><img src="https://img.shields.io/packagist/php-v/versionist/laravel-api-versionist.svg?style=flat-square" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/versionist/laravel-api-versionist"><img src="https://img.shields.io/packagist/dt/versionist/laravel-api-versionist.svg?style=flat-square" alt="Total Downloads"></a>
</p>

---

## The Problem

You're maintaining a REST API. Mobile apps are pinned to v1. Your web app needs v3. A partner integration just shipped on v2.

Now every controller looks like this:

```php
public function show(Request $request, User $user)
{
    if ($request->header('X-Api-Version') === 'v1') {
        return ['first_name' => $user->first_name, 'last_name' => $user->last_name];
    } elseif ($request->header('X-Api-Version') === 'v2') {
        return ['full_name' => $user->full_name, 'handle' => $user->handle];
    } else {
        return ['full_name' => $user->full_name, 'handle' => $user->handle, 'roles' => $user->roles];
    }
}
```

Version checks bleed into controllers, form requests, resources, and tests. Every new version multiplies the branches. Every bug fix has to be applied in three places.

**API Versionist eliminates this entirely.** Your controllers always speak the latest version. A middleware pipeline automatically transforms requests up and responses down — one transformer per version, each responsible for exactly one set of changes.

## How It Works

```
 Client (v1)                    Your API (v3)
 ─────────                      ─────────────
      │                              │
      │  POST /api/users             │
      │  X-Api-Version: v1           │
      │  { first_name, last_name }   │
      │─────────────────────────────▶│
      │                              │
      │         ┌──────────────────────────────────────┐
      │         │  Middleware: api.version              │
      │         │                                      │
      │         │  1. Detect version ──▶ v1             │
      │         │  2. Upgrade request:                  │
      │         │     v1 ─[V2Transformer]─▶ v2         │
      │         │     v2 ─[V3Transformer]─▶ v3         │
      │         │  3. Controller receives v3 data       │
      │         │  4. Controller returns v3 response    │
      │         │  5. Downgrade response:               │
      │         │     v3 ─[V3Transformer]─▶ v2         │
      │         │     v2 ─[V2Transformer]─▶ v1         │
      │         │  6. Add version headers               │
      │         └──────────────────────────────────────┘
      │                              │
      │◀─────────────────────────────│
      │  { first_name, last_name }   │
      │  X-Api-Version: v1           │
      │  X-Api-Latest-Version: v3    │
```

Your controller only ever sees v3. It never knows (or cares) that the client is on v1.

## Installation

```bash
composer require versionist/laravel-api-versionist
```

Publish the config file:

```bash
php artisan vendor:publish --tag=api-versionist-config
```

Laravel auto-discovers the service provider and facade. No manual registration needed.

## Quick Start

### 1. Generate a transformer

```bash
php artisan api:make-transformer v2
```

This creates `app/Api/Transformers/V2Transformer.php`.

### 2. Define the transformation

```php
// app/Api/Transformers/V2Transformer.php

namespace App\Api\Transformers;

use Versionist\ApiVersionist\ApiVersionTransformer;

final class V2Transformer extends ApiVersionTransformer
{
    public function version(): string
    {
        return 'v2';
    }

    public function description(): string
    {
        return 'Merged first_name + last_name into full_name.';
    }

    public function upgradeRequest(array $data): array
    {
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['full_name'] = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            unset($data['first_name'], $data['last_name']);
        }

        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        if (isset($data['full_name'])) {
            $parts = explode(' ', $data['full_name'], 2);
            $data['first_name'] = $parts[0];
            $data['last_name']  = $parts[1] ?? '';
            unset($data['full_name']);
        }

        return $data;
    }
}
```

### 3. Register it in config

```php
// config/api-versionist.php

'latest_version' => 'v2',

'transformers' => [
    App\Api\Transformers\V2Transformer::class,
],
```

### 4. Apply the middleware

```php
// routes/api.php

Route::middleware('api.version')->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
});

// Or use the versioned() macro:
Route::versioned()->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show']);
});
```

### 5. Write your controller for the latest version only

```php
// app/Http/Controllers/UserController.php

class UserController extends Controller
{
    public function show(User $user)
    {
        // Always return v2 schema — the middleware handles the rest.
        return response()->json([
            'full_name'  => $user->full_name,
            'handle'     => $user->handle,
            'avatar_url' => $user->avatar_url,
        ]);
    }
}
```

A v1 client automatically receives `{ "first_name": "Jane", "last_name": "Doe" }`. A v2 client receives `{ "full_name": "Jane Doe" }`. The controller doesn't change.

## Full Transformer Example

Each transformer defines the transition **into** its version. `upgradeRequest()` transforms requests forward (from the previous version to this one). `downgradeResponse()` transforms responses backward (from this version to the previous one).

```php
namespace App\Api\Transformers;

use Versionist\ApiVersionist\ApiVersionTransformer;

final class V3Transformer extends ApiVersionTransformer
{
    public function version(): string
    {
        return 'v3';
    }

    public function description(): string
    {
        return 'Converted role to roles array, is_active to status enum, moved timestamps into metadata.';
    }

    public function releasedAt(): ?string
    {
        return '2024-09-01';
    }

    /**
     * Upgrade: v2 request → v3 request
     */
    public function upgradeRequest(array $data): array
    {
        // role (string) → roles (array)
        if (isset($data['role']) && is_string($data['role'])) {
            $data['roles'] = [$data['role']];
            unset($data['role']);
        }

        // is_active (bool) → status (enum)
        if (array_key_exists('is_active', $data)) {
            $data['status'] = $data['is_active'] ? 'active' : 'inactive';
            unset($data['is_active']);
        }

        // Timestamps → metadata object
        if (isset($data['created_at']) || isset($data['updated_at'])) {
            $data['metadata'] = array_filter([
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ], fn ($v) => $v !== null);

            unset($data['created_at'], $data['updated_at']);
        }

        return $data;
    }

    /**
     * Downgrade: v3 response → v2 response
     */
    public function downgradeResponse(array $data): array
    {
        // roles (array) → role (string)
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['role'] = $data['roles'][0] ?? 'user';
            unset($data['roles']);
        }

        // status (enum) → is_active (bool)
        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] === 'active';
            unset($data['status']);
        }

        // metadata → top-level timestamps
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            if (isset($data['metadata']['created_at'])) {
                $data['created_at'] = $data['metadata']['created_at'];
            }
            if (isset($data['metadata']['updated_at'])) {
                $data['updated_at'] = $data['metadata']['updated_at'];
            }

            unset($data['metadata']['created_at'], $data['metadata']['updated_at']);

            if ($data['metadata'] === []) {
                unset($data['metadata']);
            }
        }

        return $data;
    }
}
```

## Version Detection Strategies

The detector tries strategies in the order you configure until one succeeds:

| Strategy | How the client sends it | Config key | Example |
|---|---|---|---|
| `url_prefix` | In the URL path | `url_prefix_pattern` | `GET /api/v2/users` |
| `header` | Custom HTTP header | `header_name` | `X-Api-Version: v2` |
| `accept_header` | Vendor media type | `accept_header_pattern` | `Accept: application/vnd.myapi+json;version=2` |
| `query_param` | Query string parameter | `query_param` | `GET /api/users?version=v2` |

Configure the priority:

```php
// config/api-versionist.php

'detection_strategies' => [
    'header',         // Try header first
    'url_prefix',     // Fall back to URL
    'query_param',    // Then query string
    // 'accept_header' // Disabled
],
```

When **no strategy** detects a version, the `default_version` is used. When an **unknown version** is detected:

- **Strict mode** (`'strict_mode' => true`): throws `UnknownVersionException` (HTTP 400)
- **Fallback mode** (`'strict_mode' => false`): silently uses `default_version`

## Envelope Support

Many APIs wrap response data in an envelope:

```json
{
    "data": { "full_name": "Jane Doe", "handle": "janedoe" },
    "meta": { "current_page": 1, "total": 50 },
    "links": { "next": "/api/users?page=2" }
}
```

Without envelope config, the transformer would receive the **entire** JSON body (including `meta` and `links`). With it, only the `data` key is transformed:

```php
// config/api-versionist.php

// Only transform the "data" key in responses — meta/links/pagination pass through untouched.
'response_data_key' => 'data',

// Transform the entire request body (no envelope).
'request_data_key' => null,
```

**Before** (v3 response from controller):

```json
{
    "data": { "full_name": "Jane Doe", "roles": ["admin"], "status": "active" },
    "meta": { "current_page": 1 },
    "links": { "next": "/api/users?page=2" }
}
```

**After** (downgraded to v1 for the client):

```json
{
    "data": { "first_name": "Jane", "last_name": "Doe", "role": "admin", "is_active": true },
    "meta": { "current_page": 1 },
    "links": { "next": "/api/users?page=2" }
}
```

`meta` and `links` are completely untouched.

## Request Macros

After the middleware runs, every `Request` instance gains these macros:

```php
// Get the resolved API version (with optional fallback)
$version = $request->apiVersion();         // "v2"
$version = $request->apiVersion('v1');     // "v1" if middleware hasn't run

// Exact match
if ($request->isApiVersion('v2')) {
    // Client is on exactly v2
}

// At least (>=)
if ($request->isApiVersionAtLeast('v2')) {
    // Client is on v2 or newer — safe to include v2+ fields
}

// Before (<)
if ($request->isApiVersionBefore('v3')) {
    // Client is on v1 or v2 — exclude v3-only features
}
```

Use these in controllers, form requests, or middleware for version-specific logic that can't be handled by transformers alone.

## Response Headers

The middleware automatically adds headers to every JSON response:

| Header | Value | When |
|---|---|---|
| `X-Api-Version` | The client's resolved version (e.g., `v1`) | Always (when `add_version_headers` is `true`) |
| `X-Api-Latest-Version` | The latest available version (e.g., `v3`) | Always (when `add_version_headers` is `true`) |
| `Deprecation` | `true` | Version is in `deprecated_versions` config |
| `Sunset` | ISO-8601 date (e.g., `2025-06-01`) | Sunset date is set for the version |
| `Link` | `</api/versions>; rel="successor-version"` | Version is deprecated and `changelog.enabled` is `true` |

Disable all version headers:

```php
'add_version_headers' => false,
```

## Deprecation & Sunset

Mark versions as deprecated to warn clients via RFC 8594 headers:

```php
// config/api-versionist.php

'deprecated_versions' => [
    'v1' => '2025-06-01',   // Sunset date known
    'v2' => null,            // Deprecated, no sunset date yet
],

'changelog' => [
    'enabled'   => true,
    'endpoint'  => '/api/versions',
    'middleware' => ['api'],
],
```

A v1 client now receives these response headers:

```
Deprecation: true
Sunset: 2025-06-01
Link: </api/versions>; rel="successor-version"
X-Api-Version: v1
X-Api-Latest-Version: v3
```

Deprecated versions still work. The headers signal that the client should migrate.

## Artisan Commands

### `api:make-transformer` — Scaffold a new transformer

```
$ php artisan api:make-transformer v4

  Creating transformer for version v4...

  ✓ Transformer created successfully!

  File: app/Api/Transformers/V4Transformer.php

  Register it in config/api-versionist.php:

    'transformers' => [
        // ... existing transformers
        App\Api\Transformers\V4Transformer::class,
    ],
```

Options:
- `--path=app/Api/Transformers` — Custom output directory
- `--force` — Overwrite existing file

### `api:versions` — List all registered versions

```
$ php artisan api:versions

  API Versions

  +---------+----------------------------------+---------+-------------+
  | Version | Transformer Class                | Status  | Released At |
  +---------+----------------------------------+---------+-------------+
  | v1      | —                                | Baseline| —           |
  | v2      | App\Api\Transformers\V2Transform | Active  | 2024-03-15  |
  | v3      | App\Api\Transformers\V3Transform | LATEST  | 2024-09-01  |
  +---------+----------------------------------+---------+-------------+
```

With `--chains`:

```
$ php artisan api:versions --chains

  Upgrade Chains
    v1 → v3  :  V2Transformer → V3Transformer
    v1 → v2  :  V2Transformer
    v2 → v3  :  V3Transformer

  Downgrade Chains
    v3 → v1  :  V3Transformer → V2Transformer
    v3 → v2  :  V3Transformer
    v2 → v1  :  V2Transformer
```

### `api:changelog` — Display version changelog

```
$ php artisan api:changelog

  API Version Changelog
  ──────────────────────────────────────────────

  v1 (baseline)
  The original API version before any transforms.

  v2  [Active]  Released: 2024-03-15
  Merged first_name + last_name into full_name.

  v3  [LATEST]  Released: 2024-09-01
  Converted role to roles array, is_active to status enum.

  ──────────────────────────────────────────────
  3 versions registered (baseline: v1)
```

Formats: `--format=table` (default), `--format=json`, `--format=markdown`

### `api:audit` — Validate transformers and dry-run pipelines

```
$ php artisan api:audit

  API Versionist Audit
  ────────────────────────────────────────

  Checking v2 (App\Api\Transformers\V2Transformer)
    ✓ Implements VersionTransformerInterface
    ✓ version() returns valid string: "v2"
    ✓ upgradeRequest() transforms data
    ✓ downgradeResponse() transforms data

  Checking v3 (App\Api\Transformers\V3Transformer)
    ✓ Implements VersionTransformerInterface
    ✓ version() returns valid string: "v3"
    ✓ upgradeRequest() transforms data
    ✓ downgradeResponse() transforms data

  Pipeline Dry-Run
  ────────────────────────────────────────
    ✓ Upgrade v1 → v3: 2 transformer(s) applied [V2Transformer → V3Transformer]
    ✓ Downgrade v3 → v1: 2 transformer(s) applied [V3Transformer → V2Transformer]

  ────────────────────────────────────────
  Result: 10 passed, 0 warnings, 0 errors
```

Options:
- `--from=v1 --to=v3` — Run a targeted pipeline dry-run between specific versions

## Multi-Version Walkthrough

Here's exactly what happens when a **v1 client** hits an API running **v3**, with both V2Transformer and V3Transformer registered.

### Request: `POST /api/users` with `X-Api-Version: v1`

```json
{
    "first_name": "Jane",
    "last_name": "Doe",
    "username": "janedoe",
    "profile_image": "https://cdn.example.com/jane.jpg",
    "role": "admin",
    "is_active": true,
    "created_at": "2024-01-15T10:00:00Z"
}
```

**Step 1 — Detect version:** Header `X-Api-Version: v1` → resolved version: `v1`

**Step 2 — Upgrade v1 → v2** (V2Transformer.upgradeRequest):

```json
{
    "full_name": "Jane Doe",
    "handle": "janedoe",
    "avatar_url": "https://cdn.example.com/jane.jpg",
    "role": "admin",
    "is_active": true,
    "created_at": "2024-01-15T10:00:00Z"
}
```

**Step 3 — Upgrade v2 → v3** (V3Transformer.upgradeRequest):

```json
{
    "full_name": "Jane Doe",
    "handle": "janedoe",
    "avatar_url": "https://cdn.example.com/jane.jpg",
    "roles": ["admin"],
    "status": "active",
    "metadata": { "created_at": "2024-01-15T10:00:00Z" }
}
```

**Step 4 — Controller** receives the v3 payload. It only knows v3. It processes the request and returns a v3 response:

```json
{
    "full_name": "Jane Doe",
    "handle": "janedoe",
    "avatar_url": "https://cdn.example.com/jane.jpg",
    "roles": ["admin"],
    "status": "active",
    "metadata": { "created_at": "2024-01-15T10:00:00Z", "updated_at": "2024-09-01T12:00:00Z" }
}
```

**Step 5 — Downgrade v3 → v2** (V3Transformer.downgradeResponse):

```json
{
    "full_name": "Jane Doe",
    "handle": "janedoe",
    "avatar_url": "https://cdn.example.com/jane.jpg",
    "role": "admin",
    "is_active": true,
    "created_at": "2024-01-15T10:00:00Z",
    "updated_at": "2024-09-01T12:00:00Z"
}
```

**Step 6 — Downgrade v2 → v1** (V2Transformer.downgradeResponse):

```json
{
    "first_name": "Jane",
    "last_name": "Doe",
    "username": "janedoe",
    "profile_image": "https://cdn.example.com/jane.jpg",
    "role": "admin",
    "is_active": true,
    "created_at": "2024-01-15T10:00:00Z",
    "updated_at": "2024-09-01T12:00:00Z"
}
```

The v1 client receives v1-shaped data. The controller never knew it existed.

## Events

Listen for transformation events to log, audit, or debug:

```php
// app/Providers/EventServiceProvider.php

use Versionist\ApiVersionist\Events\RequestUpgraded;
use Versionist\ApiVersionist\Events\ResponseDowngraded;

protected $listen = [
    RequestUpgraded::class => [
        LogApiUpgrade::class,
    ],
    ResponseDowngraded::class => [
        LogApiDowngrade::class,
    ],
];
```

Event properties:

```php
// RequestUpgraded
$event->request;       // Illuminate\Http\Request
$event->fromVersion;   // "v1"
$event->toVersion;     // "v3"
$event->originalData;  // The pre-upgrade array
$event->upgradedData;  // The post-upgrade array

// ResponseDowngraded
$event->response;        // Illuminate\Http\JsonResponse
$event->fromVersion;     // "v3"
$event->toVersion;       // "v1"
$event->originalData;    // The pre-downgrade array
$event->downgradedData;  // The post-downgrade array
```

## Testing Your Transformers

Test transformers as plain PHP units — no HTTP needed:

```php
use App\Api\Transformers\V2Transformer;
use PHPUnit\Framework\TestCase;

class V2TransformerTest extends TestCase
{
    private V2Transformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new V2Transformer();
    }

    public function test_upgrade_merges_name_fields(): void
    {
        $result = $this->transformer->upgradeRequest([
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
        ]);

        $this->assertSame('Jane Doe', $result['full_name']);
        $this->assertArrayNotHasKey('first_name', $result);
        $this->assertArrayNotHasKey('last_name', $result);
    }

    public function test_downgrade_splits_full_name(): void
    {
        $result = $this->transformer->downgradeResponse([
            'full_name' => 'Jane Doe',
        ]);

        $this->assertSame('Jane', $result['first_name']);
        $this->assertSame('Doe', $result['last_name']);
        $this->assertArrayNotHasKey('full_name', $result);
    }

    public function test_round_trip_preserves_data(): void
    {
        $original = ['first_name' => 'Jane', 'last_name' => 'Doe'];

        $upgraded   = $this->transformer->upgradeRequest($original);
        $downgraded = $this->transformer->downgradeResponse($upgraded);

        $this->assertSame($original, $downgraded);
    }
}
```

Run the built-in audit to validate all registered transformers:

```bash
php artisan api:audit
```

## Configuration Reference

```php
// config/api-versionist.php

return [
    'default_version'      => 'v1',         // Fallback when no version detected
    'latest_version'       => 'v3',         // Your current API version
    'strict_mode'          => false,        // Throw on unknown versions?
    'add_version_headers'  => true,         // Add X-Api-Version headers?

    'detection_strategies' => [             // Tried in order
        'url_prefix',
        'header',
        'accept_header',
        'query_param',
    ],

    'header_name'            => 'X-Api-Version',
    'query_param'            => 'version',
    'url_prefix_pattern'     => '/\/(?:api\/)?(?P<version>[vV]\d+(?:\.\d+)?)(?:\/|$)/',
    'accept_header_pattern'  => '/application\/vnd\.[a-zA-Z0-9.-]+\+json;\s*version=(?P<version>[vV]?\d+(?:\.\d+)?)/',

    'transformers' => [                     // Register your transformers
        App\Api\Transformers\V2Transformer::class,
        App\Api\Transformers\V3Transformer::class,
    ],

    'response_data_key' => 'data',          // null = transform entire body
    'request_data_key'  => null,            // null = transform entire body

    'deprecated_versions' => [
        'v1' => '2025-06-01',
    ],

    'changelog' => [
        'enabled'    => false,
        'endpoint'   => '/api/versions',
        'middleware'  => ['api'],
    ],
];
```

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Write tests for your changes
4. Run the test suite (`composer test`)
5. Submit a pull request

Please make sure all tests pass and follow the existing code style.

### Running Tests

```bash
composer install
vendor/bin/phpunit
```

The test suite includes 109 tests covering every core class and end-to-end scenario.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
