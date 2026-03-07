# Laravel API Versionist

**Your API changes. Your controllers don't.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/versionist/laravel-api-versionist)](https://packagist.org/packages/versionist/laravel-api-versionist)
[![PHP Version](https://img.shields.io/packagist/php-v/versionist/laravel-api-versionist)](https://packagist.org/packages/versionist/laravel-api-versionist)
[![Tests](https://github.com/jay123anta/laravel-api-versionist/actions/workflows/tests.yml/badge.svg)](https://github.com/jay123anta/laravel-api-versionist/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/versionist/laravel-api-versionist)](https://packagist.org/packages/versionist/laravel-api-versionist)
[![Total Downloads](https://img.shields.io/packagist/dt/versionist/laravel-api-versionist)](https://packagist.org/packages/versionist/laravel-api-versionist)

Laravel API Versionist handles the messy work of supporting multiple API versions. You write small transformer classes that describe what changed between versions, and the package automatically converts requests and responses on every API call — so your controllers only ever speak the latest version.

Supports Laravel 10 & 11 · PHP 8.1+ · 110 tests, 224 assertions

---

## Table of Contents

1. [The Problem](#the-problem)
2. [How It Works](#how-it-works)
3. [Installation](#installation)
4. [Quick Start](#quick-start)
5. [Core Concept — Transformers](#core-concept--transformers)
6. [Multi-Version Walkthrough](#multi-version-walkthrough)
7. [Version Detection](#version-detection)
8. [Configuration Reference](#configuration-reference)
9. [Response Headers](#response-headers)
10. [Request Macros](#request-macros)
11. [Artisan Commands](#artisan-commands)
12. [Envelope Mode](#envelope-mode)
13. [Known Limitations](#known-limitations)
14. [Real-World Example](#real-world-example)
15. [Testing Your Transformers](#testing-your-transformers)
16. [FAQ](#faq)
17. [Contributing](#contributing)
18. [License](#license)

---

## The Problem

You built an API. Version 1 returns users like this:

```json
{
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com"
}
```

A mobile app is using it in production. Thousands of users. You can't break it.

Now you need version 2. The product team wants `first_name` and `last_name` merged into a single `full_name` field. You have three options:

### Option A: Duplicate the controller

```php
// app/Http/Controllers/V1/UserController.php
class UserController extends Controller
{
    public function show(User $user)
    {
        return response()->json([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ]);
    }
}

// app/Http/Controllers/V2/UserController.php  ← Copy-paste of the entire file
class UserController extends Controller
{
    public function show(User $user)
    {
        return response()->json([
            'full_name' => $user->full_name,
            'email_address' => $user->email,
        ]);
    }
}
```

Now you have two controllers, two sets of routes, and every bug fix needs to be applied in both places. By version 5, you have five copies.

### Option B: Version spaghetti

```php
class UserController extends Controller
{
    public function show(Request $request, User $user)
    {
        if ($request->header('X-Api-Version') === 'v1') {
            return response()->json([
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ]);
        } elseif ($request->header('X-Api-Version') === 'v2') {
            return response()->json([
                'full_name' => $user->full_name,
                'email_address' => $user->email,
            ]);
        } else {
            return response()->json([
                'full_name' => $user->full_name,
                'email_address' => $user->email,
                'roles' => $user->roles,
            ]);
        }
    }
}
```

Every controller turns into a nest of `if/else` branches. Every new version multiplies the mess.

### Option C: This package

```php
class UserController extends Controller
{
    public function show(User $user)
    {
        // Always return the latest version. That's it.
        return response()->json([
            'full_name' => $user->full_name,
            'email_address' => $user->email,
            'roles' => $user->roles,
        ]);
    }
}
```

One controller. One version. The package handles everything else.

**There has to be a better way. This is it.**

---

## How It Works

When a client sends a request, the package figures out which API version they're using. If they're on an older version, it upgrades their request data to the latest format before your controller sees it. When the controller sends back a response, the package downgrades it back to the format the client expects.

Your controller always works with the latest version. It never knows (or cares) what version the client is using.

```
 ┌──────────┐  upgrade chain   ┌────────────┐
 │ v1 Client│ ───────────────► │ Controller │ ← always writes v3
 │ Request  │  v1 → v2 → v3   │ (latest)   │
 └──────────┘                  └─────┬──────┘
                                     │ v3 response
 ┌──────────┐  downgrade chain       │
 │ v1 Client│ ◄────────────────┌─────▼──────┐
 │ Response │  v3 → v2 → v1   │  Response  │
 └──────────┘                  └────────────┘
```

**Your controller never changes. Only your transformer classes change.**

---

## Installation

```bash
composer require versionist/laravel-api-versionist
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=api-versionist-config
```

That's it. Laravel auto-discovers the service provider and facade — no manual registration needed.

---

## Quick Start

This guide walks you through adding your first API version in 5 steps.

### Step 1: Generate your first transformer

A transformer is a small class that describes what changed between two versions. This command creates one for you.

```bash
php artisan api:make-transformer v2
```

This creates `app/Api/Transformers/V2Transformer.php` with a ready-to-fill skeleton.

### Step 2: Define what changed

Open the generated file and fill in the two transformation methods. Each method handles one direction — `upgradeRequest()` converts old data to new, and `downgradeResponse()` converts new data to old.

```php
<?php

// app/Api/Transformers/V2Transformer.php

namespace App\Api\Transformers;

use Versionist\ApiVersionist\ApiVersionTransformer;

final class V2Transformer extends ApiVersionTransformer
{
    public function version(): string
    {
        // This transformer handles the transition TO v2
        return 'v2';
    }

    public function description(): string
    {
        // A human-readable summary — shown in artisan commands
        return 'Merged first_name + last_name into full_name, renamed email to email_address.';
    }

    public function upgradeRequest(array $data): array
    {
        // Called when a v1 client sends a request.
        // Convert v1 fields → v2 fields so the controller always sees v2.

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['full_name'] = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            unset($data['first_name'], $data['last_name']);
        }

        if (array_key_exists('email', $data)) {
            $data['email_address'] = $data['email'];
            unset($data['email']);
        }

        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        // Called when sending a response TO a v1 client.
        // Convert v2 fields → v1 fields so the client gets what it expects.

        if (isset($data['full_name'])) {
            $parts = explode(' ', $data['full_name'], 2);
            $data['first_name'] = $parts[0];
            $data['last_name'] = $parts[1] ?? '';
            unset($data['full_name']);
        }

        if (array_key_exists('email_address', $data)) {
            $data['email'] = $data['email_address'];
            unset($data['email_address']);
        }

        return $data;
    }
}
```

### Step 3: Register it in config

Tell the package about your transformer and what your latest version is.

```php
// config/api-versionist.php

// Update this to match your highest transformer version
'latest_version' => 'v2',

// List all your transformer classes here
'transformers' => [
    App\Api\Transformers\V2Transformer::class,
],

// If your controller returns flat JSON (not wrapped in a "data" key),
// set this to null. See the Envelope Mode section for details.
'response_data_key' => null,
```

> **Important:** If you leave `latest_version` as `'v1'` after adding a V2Transformer, the package thinks your API is still on v1 and no transformers will run. Always update this value.

### Step 4: Add middleware to your API routes

The `api.version` middleware is what makes everything happen. Add it to any routes you want versioned.

```php
// routes/api.php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Option A: Use the middleware directly
Route::middleware('api.version')->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
});

// Option B: Use the versioned() route macro (same thing, shorter syntax)
Route::versioned()->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show']);
});
```

### Step 5: Make a request — see it work

Your controller only returns the latest version:

```php
// app/Http/Controllers/UserController.php

class UserController extends Controller
{
    public function show(User $user)
    {
        // Always return v2 format. The middleware handles the rest.
        return response()->json([
            'full_name' => $user->full_name,
            'email_address' => $user->email,
        ]);
    }
}
```

Now test it:

```bash
# v2 client — gets v2 response directly (no transformation)
curl -H "X-Api-Version: v2" http://your-app.test/api/users/1
# → {"full_name": "Jane Doe", "email_address": "jane@example.com"}

# v1 client — gets v1 response (automatically downgraded)
curl -H "X-Api-Version: v1" http://your-app.test/api/users/1
# → {"first_name": "Jane", "last_name": "Doe", "email": "jane@example.com"}
```

**Done. Your v1 clients still work. Your v2 controller is clean.**

> **Tip for beginners:** You can install the package and add the middleware before creating any transformers. Everything passes through untouched — no errors, no changes. You can adopt incrementally.

---

## Core Concept — Transformers

Every transformer has two methods that matter:

- **`upgradeRequest(array $data): array`** — Called when an OLD client sends a request. Converts old field names to new ones so your controller always sees the latest format.

- **`downgradeResponse(array $data): array`** — Called when sending a response TO an old client. Converts new field names back to old ones so the client gets what it expects.

Here is a complete, real transformer that handles three field changes:

```php
<?php

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
        return 'Renamed username to handle, converted role string to roles array.';
    }

    public function releasedAt(): ?string
    {
        return '2025-03-01';
    }

    public function upgradeRequest(array $data): array
    {
        // username (string) → handle (string)
        if (isset($data['username'])) {
            $data['handle'] = $data['username'];
            unset($data['username']);
        }

        // role (string) → roles (array)
        if (isset($data['role']) && is_string($data['role'])) {
            $data['roles'] = [$data['role']];
            unset($data['role']);
        }

        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        // handle (string) → username (string)
        if (isset($data['handle'])) {
            $data['username'] = $data['handle'];
            unset($data['handle']);
        }

        // roles (array) → role (string)
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['role'] = $data['roles'][0] ?? 'user';
            unset($data['roles']);
        }

        return $data;
    }
}
```

**What a v1 client sends:**

```json
{ "username": "janedoe", "role": "admin" }
```

**What your controller receives (after upgrade):**

```json
{ "handle": "janedoe", "roles": ["admin"] }
```

**What a v1 client gets back (after downgrade):**

```json
{ "username": "janedoe", "role": "admin" }
```

> **Important:** Each transformer only describes what changed IN ITS version. A V2Transformer handles the v1-to-v2 transition. A V3Transformer handles v2-to-v3. Nothing more. The package chains them together automatically.

---

## Multi-Version Walkthrough

Let's walk through a real scenario with three versions. Here's how the user schema evolved:

| Version | Fields |
|---------|--------|
| v1 | `username`, `role`, `is_active` |
| v2 | `handle`, `role`, `is_active` ← V2Transformer: renamed `username` → `handle` |
| v3 | `handle`, `roles[]`, `status` ← V3Transformer: `role` → `roles[]`, `is_active` → `status` |

### A v1 client sends a POST request

```json
{ "username": "janedoe", "role": "admin", "is_active": true }
```

Here is exactly what happens, step by step:

**1. Detect version** → URL or header tells the package: this is a v1 request

**2. Upgrade v1 → v2** (V2Transformer runs `upgradeRequest`):
- `username` → `handle`

```json
{ "handle": "janedoe", "role": "admin", "is_active": true }
```

**3. Upgrade v2 → v3** (V3Transformer runs `upgradeRequest`):
- `role` → `roles[]`
- `is_active` → `status`

```json
{ "handle": "janedoe", "roles": ["admin"], "status": "active" }
```

**4. Controller receives v3 data.** It only knows v3. It processes the request and returns a v3 response:

```json
{ "handle": "janedoe", "roles": ["admin", "editor"], "status": "active" }
```

**5. Downgrade v3 → v2** (V3Transformer runs `downgradeResponse`):
- `roles[]` → `role`
- `status` → `is_active`

```json
{ "handle": "janedoe", "role": "admin", "is_active": true }
```

**6. Downgrade v2 → v1** (V2Transformer runs `downgradeResponse`):
- `handle` → `username`

```json
{ "username": "janedoe", "role": "admin", "is_active": true }
```

**The v1 client receives v1-shaped data. The controller never knew it existed.**

> **Tip for beginners:** Think of transformers like language translators. V2Transformer translates between v1 and v2. V3Transformer translates between v2 and v3. If someone speaks v1 and your controller speaks v3, the package chains both translators together automatically.

---

## Version Detection

The package needs to figure out which version a client is using. It has four strategies and tries them in the order you configure. The first one that finds a version wins.

| Strategy | How the client sends it | Example |
|---|---|---|
| `url_prefix` | In the URL path | `GET /api/v2/users` |
| `header` | Custom HTTP header | `X-Api-Version: v2` |
| `query_param` | Query string parameter | `GET /api/users?version=v2` |
| `accept_header` | Vendor media type | `Accept: application/vnd.api+json;version=2` |

Configure the order in your config file:

```php
// config/api-versionist.php

'detection_strategies' => [
    'url_prefix',     // Try URL first (/api/v2/users)
    'header',         // Then check for X-Api-Version header
    'query_param',    // Then check ?version= parameter
    // 'accept_header' // Uncomment to enable Accept header detection
],
```

When no strategy detects a version, the `default_version` (usually `'v1'`) is used.

When an unknown version is detected (like `v99`):

- **Strict mode off** (default): Silently falls back to `default_version`
- **Strict mode on**: Throws an `UnknownVersionException` (returns HTTP 400)

> **Tip for beginners:** Not sure which strategy to use? Start with `url_prefix` — it's the most visible and easiest to debug. You can see the version right in the URL: `/api/v2/users`.

---

## Configuration Reference

Every option in `config/api-versionist.php` explained:

| Key | Default | Description |
|---|---|---|
| `default_version` | `'v1'` | The version to assume when no version is detected from the request. Also the fallback in non-strict mode. |
| `latest_version` | `'v1'` | Your current API version. Must match your highest transformer. If this is wrong, transformers won't run. |
| `transformers` | `[]` | Array of transformer class names. Register every transformer here. |
| `deprecated_versions` | `[]` | Map of version → sunset date (or `null`). Deprecated versions still work but emit warning headers. |
| `strict_mode` | `false` | When `true`, unknown versions throw `UnknownVersionException` (HTTP 400). When `false`, falls back to `default_version`. |
| `response_data_key` | `'data'` | The key in JSON responses to transform. Set to `null` to transform the entire response body. See [Envelope Mode](#envelope-mode). |
| `request_data_key` | `null` | The key in JSON requests to transform. Set to `null` to transform the entire request body. |
| `add_version_headers` | `true` | Adds `X-Api-Version` and `X-Api-Latest-Version` headers to every JSON response. |
| `detection_strategies` | `['url_prefix', 'header', 'accept_header', 'query_param']` | Ordered list of strategies. First match wins. |
| `header_name` | `'X-Api-Version'` | HTTP header name for the `header` detection strategy. |
| `query_param` | `'version'` | Query parameter name for the `query_param` strategy. |
| `url_prefix_pattern` | *(see config file)* | Regex to extract version from URL path. Must have a `(?P<version>...)` capture group. |
| `accept_header_pattern` | *(see config file)* | Regex to extract version from Accept header. Must have a `(?P<version>...)` capture group. |
| `changelog.enabled` | `false` | When `true`, registers a route that exposes version metadata as JSON. |
| `changelog.endpoint` | `'/api/versions'` | The URL path for the changelog endpoint. |
| `changelog.middleware` | `['api']` | Middleware applied to the changelog route. |

> **Important:** The default `response_data_key` is `'data'` — designed for APIs that wrap responses in `{"data": {...}}` (like Laravel API Resources). If your controller returns flat JSON like `response()->json([...])`, you **must** set this to `null` or your transformers won't run on the response. See [Envelope Mode](#envelope-mode).

---

## Response Headers

The middleware automatically adds headers to every JSON response so clients know which version they're using and whether they should upgrade.

**Always added** (when `add_version_headers` is `true`):

```
X-Api-Version: v2
X-Api-Latest-Version: v3
```

**Added for deprecated versions:**

```
Deprecation: true
Sunset: 2025-12-31
Link: </api/versions>; rel="successor-version"
```

The `Sunset` header only appears if you set a date. The `Link` header only appears if the changelog endpoint is enabled.

### Marking a version as deprecated

```php
// config/api-versionist.php

'deprecated_versions' => [
    'v1' => '2025-12-31',  // Sunset date known — version will be removed on this date
    'v2' => null,           // Deprecated, but no sunset date decided yet
],

// Enable the changelog endpoint so the Link header has somewhere to point
'changelog' => [
    'enabled' => true,
    'endpoint' => '/api/versions',
    'middleware' => ['api'],
],
```

> **Tip for beginners:** These headers are part of [RFC 8594](https://tools.ietf.org/html/rfc8594). They tell API clients "this version is going away — please upgrade before the sunset date." Deprecated versions still work normally.

To disable all version headers:

```php
'add_version_headers' => false,
```

---

## Request Macros

After the middleware runs, every `Request` instance gains four convenience methods:

```php
// Get the resolved API version (with optional fallback)
$request->apiVersion();          // "v2"
$request->apiVersion('v1');      // "v1" if middleware hasn't run yet

// Exact match
$request->isApiVersion('v2');    // true

// At least (>=)
$request->isApiVersionAtLeast('v2');  // true for v2, v3, v4...

// Before (<)
$request->isApiVersionBefore('v3');   // true for v1, v2
```

### Real use case: feature flags and logging

```php
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::paginate();

        // Version-specific logic that can't be handled by transformers
        if ($request->isApiVersionAtLeast('v3')) {
            // v3+ clients get a new field that requires a DB query
            $users->each(fn ($user) => $user->append('login_streak'));
        }

        // Log which versions are still in use
        logger('API hit', [
            'version' => $request->apiVersion(),
            'endpoint' => '/users',
        ]);

        return response()->json($users);
    }
}
```

---

## Artisan Commands

### `api:make-transformer` — Scaffold a new transformer

Creates a ready-to-fill transformer class from a stub template.

```bash
php artisan api:make-transformer v4
```

```
  Creating transformer for version v4...

  ✓ Transformer created successfully!

  File: app/Api/Transformers/V4Transformer.php

  Register it in config/api-versionist.php:

    'transformers' => [
        // ... existing transformers
        App\Api\Transformers\V4Transformer::class,
    ],
```

Options: `--path=app/Api/Transformers` (custom directory), `--force` (overwrite existing)

### `api:versions` — List all registered versions

Shows every version, its transformer class, status, and release date.

```bash
php artisan api:versions
```

```
  API Versions

  +---------+------------------------------------+------------+-------------+
  | Version | Transformer Class                  | Status     | Released At |
  +---------+------------------------------------+------------+-------------+
  | v1      | —                                  | Baseline   | —           |
  | v2      | App\Api\Transformers\V2Transformer | Deprecated | 2025-03-01  |
  | v3      | App\Api\Transformers\V3Transformer | LATEST     | 2025-06-01  |
  +---------+------------------------------------+------------+-------------+
```

Add `--chains` to see upgrade and downgrade chains:

```bash
php artisan api:versions --chains
```

```
  Upgrade Chains
    v1 → v2  :  V2Transformer
    v1 → v3  :  V2Transformer → V3Transformer
    v2 → v3  :  V3Transformer

  Downgrade Chains
    v3 → v2  :  V3Transformer
    v3 → v1  :  V3Transformer → V2Transformer
    v2 → v1  :  V2Transformer
```

### `api:changelog` — Display version changelog

Shows a human-readable changelog of all API versions.

```bash
php artisan api:changelog
```

```
  API Version Changelog
  ──────────────────────────────────────────────

  v1 (baseline)
  The original API version before any transforms.

  v2  [DEPRECATED]  Released: 2025-03-01
  Merged first_name + last_name into full_name, renamed email to email_address.

  v3  [LATEST]  Released: 2025-06-01
  Replaced role string with roles array; added status enum.

  ──────────────────────────────────────────────
  3 versions registered (baseline: v1)
```

Formats: `--format=table` (default), `--format=json`, `--format=markdown`

### `api:audit` — Validate transformers

Checks that all registered transformers implement the interface correctly and dry-runs the pipeline.

```bash
php artisan api:audit --from=v1 --to=v3
```

```
  API Versionist Audit
  ────────────────────────────────────────

  Checking v2 (App\Api\Transformers\V2Transformer)
    ✓ Implements VersionTransformerInterface
    ✓ version() returns valid string: "v2"
    ⚠ upgradeRequest() returned data unchanged (possible no-op)
    ⚠ downgradeResponse() returned data unchanged (possible no-op)

  Checking v3 (App\Api\Transformers\V3Transformer)
    ✓ Implements VersionTransformerInterface
    ✓ version() returns valid string: "v3"
    ⚠ upgradeRequest() returned data unchanged (possible no-op)
    ⚠ downgradeResponse() returned data unchanged (possible no-op)

  Pipeline Dry-Run
  ────────────────────────────────────────
    ✓ Upgrade v1 → v3: 2 transformer(s) applied [V2Transformer → V3Transformer]

  ────────────────────────────────────────
  Result: 5 passed, 4 warnings
```

> **Tip for beginners:** The warnings about "returned data unchanged" are normal — the audit tests with an empty array, so there are no fields to transform. This is not an error.

---

## Envelope Mode

Many APIs wrap their response data inside a container — this is called an "envelope":

```json
{
    "data": { "full_name": "Jane Doe", "email_address": "jane@example.com" },
    "meta": { "current_page": 1, "total": 50 },
    "links": { "next": "/api/users?page=2" }
}
```

The problem: if the package tries to transform the entire response, it would look for `full_name` at the top level and find nothing — because the actual data is nested inside `"data"`.

The solution: tell the package which key contains the transformable data.

```php
// config/api-versionist.php

// Only transform what's inside the "data" key — meta, links, pagination are untouched
'response_data_key' => 'data',

// For requests, transform what's inside the "data" key too (or null for entire body)
'request_data_key' => 'data',
```

**Before** (v3 response from your controller):

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

Only `"data"` was transformed. `"meta"` and `"links"` pass through untouched.

> **Important:** The default `response_data_key` is `'data'`. If your controller returns flat JSON without a `"data"` wrapper (like `response()->json(['full_name' => '...'])`), you **must** set this to `null` — otherwise the package won't find any data to transform.

---

## Known Limitations

### Limitation 1: Flat array responses are not auto-transformed

**What happens:** If your controller returns an array of objects (like a user list), the transformer receives the entire array — not each individual object.

```php
// Controller returns an array of users
return response()->json([
    ['full_name' => 'Jane Doe', 'roles' => ['admin']],
    ['full_name' => 'John Smith', 'roles' => ['user']],
]);
```

The transformer sees `{0: {...}, 1: {...}}` and looks for keys like `full_name` at the top level — which don't exist because the top-level keys are `0` and `1`.

**Why:** The transformer pipeline is designed to operate on a single known object shape, not iterate over collections. This keeps the architecture simple and predictable.

**Workaround:** Use envelope mode. Wrap your list responses in a `"data"` key and set `response_data_key` to `'data'`:

```php
// Controller
return response()->json([
    'data' => [
        ['full_name' => 'Jane Doe', 'roles' => ['admin']],
        ['full_name' => 'John Smith', 'roles' => ['user']],
    ],
    'meta' => ['total' => 2],
]);
```

```php
// Config
'response_data_key' => 'data',
```

> **Tip for beginners:** Most production APIs already use this pattern (Laravel API Resources do it by default). If you're starting fresh, wrapping responses in `"data"` is a good practice regardless.

### Limitation 2: Nested response keys are not auto-transformed

**What happens:** If your controller returns a response with nested objects (like `{"message": "...", "user": {...}}`), only the top-level keys or the configured `response_data_key` are transformed — not arbitrary nested keys.

**Why:** The package transforms one known location. It doesn't recursively walk your entire response looking for things to change.

**Workaround:** Structure your responses so that all transformable data lives at the top level or inside the configured `response_data_key`. For example, return the user object directly instead of nesting it under a `"user"` key.

---

## Real-World Example

Here's a complete, copy-paste-ready mini-app with versioned users.

### routes/api.php

```php
<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.version')->group(function () {
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
});
```

### app/Api/Transformers/V2Transformer.php

```php
<?php

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
        return 'Merged first_name + last_name into full_name, renamed email to email_address.';
    }

    public function upgradeRequest(array $data): array
    {
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['full_name'] = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            unset($data['first_name'], $data['last_name']);
        }

        if (array_key_exists('email', $data)) {
            $data['email_address'] = $data['email'];
            unset($data['email']);
        }

        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        if (isset($data['full_name'])) {
            $parts = explode(' ', $data['full_name'], 2);
            $data['first_name'] = $parts[0];
            $data['last_name'] = $parts[1] ?? '';
            unset($data['full_name']);
        }

        if (array_key_exists('email_address', $data)) {
            $data['email'] = $data['email_address'];
            unset($data['email_address']);
        }

        return $data;
    }
}
```

### app/Http/Controllers/UserController.php

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        // Always return the latest version (v2)
        return response()->json([
            'id' => $id,
            'full_name' => 'Jane Doe',
            'email_address' => 'jane@example.com',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // The middleware has already upgraded the request to v2
        // $request->all() always has v2 fields, even if the client sent v1
        return response()->json([
            'message' => 'User created',
            'id' => 42,
            'full_name' => $request->input('full_name'),
            'email_address' => $request->input('email_address'),
        ], 201);
    }
}
```

### config/api-versionist.php (relevant parts)

```php
'latest_version' => 'v2',
'default_version' => 'v1',
'response_data_key' => null,
'request_data_key' => null,
'transformers' => [
    App\Api\Transformers\V2Transformer::class,
],
```

### Test it with curl

```bash
# v2 client — gets v2 response (no transformation)
curl -s -H "X-Api-Version: v2" http://your-app.test/api/users/1
```

```json
{"id":1,"full_name":"Jane Doe","email_address":"jane@example.com"}
```

```bash
# v1 client — gets v1 response (automatically downgraded)
curl -s -H "X-Api-Version: v1" http://your-app.test/api/users/1
```

```json
{"id":1,"first_name":"Jane","last_name":"Doe","email":"jane@example.com"}
```

```bash
# v1 client sends v1 POST — request upgraded, response downgraded
curl -s -X POST -H "X-Api-Version: v1" -H "Content-Type: application/json" \
  -d '{"first_name":"Alice","last_name":"Wonder","email":"alice@example.com"}' \
  http://your-app.test/api/users
```

```json
{"message":"User created","id":42,"first_name":"Alice","last_name":"Wonder","email":"alice@example.com"}
```

---

## Testing Your Transformers

### Unit testing (test the transformer in isolation)

Transformers are plain PHP classes — no HTTP, no framework needed:

```php
<?php

namespace Tests\Unit;

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
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame('Jane Doe', $result['full_name']);
        $this->assertSame('jane@example.com', $result['email_address']);
        $this->assertArrayNotHasKey('first_name', $result);
        $this->assertArrayNotHasKey('last_name', $result);
        $this->assertArrayNotHasKey('email', $result);
    }

    public function test_downgrade_splits_full_name(): void
    {
        $result = $this->transformer->downgradeResponse([
            'full_name' => 'Jane Doe',
            'email_address' => 'jane@example.com',
        ]);

        $this->assertSame('Jane', $result['first_name']);
        $this->assertSame('Doe', $result['last_name']);
        $this->assertSame('jane@example.com', $result['email']);
        $this->assertArrayNotHasKey('full_name', $result);
    }

    public function test_round_trip_preserves_data(): void
    {
        $original = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ];

        $upgraded = $this->transformer->upgradeRequest($original);
        $downgraded = $this->transformer->downgradeResponse($upgraded);

        $this->assertSame($original, $downgraded);
    }
}
```

### Full pipeline testing (test the middleware end-to-end)

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Versionist\ApiVersionist\ApiVersionistServiceProvider;

class ApiVersioningTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ApiVersionistServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-versionist.latest_version', 'v2');
        $app['config']->set('api-versionist.response_data_key', null);
        $app['config']->set('api-versionist.transformers', [
            \App\Api\Transformers\V2Transformer::class,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api.version')->get('/api/test', function () {
            return response()->json([
                'full_name' => 'Jane Doe',
                'email_address' => 'jane@example.com',
            ]);
        });
    }

    public function test_v1_client_receives_v1_response(): void
    {
        $response = $this->getJson('/api/test', ['X-Api-Version' => 'v1']);

        $response->assertOk();
        $response->assertJson([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);
        $response->assertHeader('X-Api-Version', 'v1');
        $response->assertHeader('X-Api-Latest-Version', 'v2');
    }

    public function test_v2_client_receives_v2_response(): void
    {
        $response = $this->getJson('/api/test', ['X-Api-Version' => 'v2']);

        $response->assertOk();
        $response->assertJson([
            'full_name' => 'Jane Doe',
            'email_address' => 'jane@example.com',
        ]);
    }
}
```

You can also validate all transformers with a single command:

```bash
php artisan api:audit
```

---

## FAQ

**Q: Do I need to change my controllers for every version?**

No. That's the whole point. Your controllers always return the latest version. When you release v3, you update your controllers to return v3 data and write a V3Transformer that describes the difference between v2 and v3. Old clients still get their expected format automatically.

**Q: What happens if I forget to implement `downgradeResponse()`?**

Nothing breaks. The base class has a default no-op implementation that returns data unchanged. But your old clients will receive the new field names, which they might not understand. Always implement both methods.

**Q: Can I use this for internal APIs (not just public)?**

Yes. The package doesn't care who's calling the API. It works for mobile apps, SPAs, partner integrations, microservices — anything that sends HTTP requests to your Laravel app.

**Q: What if my v1 and v3 are completely different?**

You still write one transformer per version step. V2Transformer handles v1→v2 changes, V3Transformer handles v2→v3 changes. Even if the difference between v1 and v3 is massive, each transformer only handles one step. The package chains them automatically.

**Q: How is this different from just making new controllers?**

With separate controllers, you maintain N copies of every endpoint. Bug fixes and new features need to be applied to all copies. With this package, you maintain one controller (latest version) plus one small transformer class per version. When you fix a bug in the controller, every version gets the fix automatically.

**Q: Can I skip a version? (e.g., go from v1 directly to v3?)**

Yes. If a v1 client calls your v3 API, the package runs V2Transformer then V3Transformer in sequence. You don't need to write a combined v1→v3 transformer. The chain handles it.

**Q: What happens to fields I don't mention in the transformer?**

They pass through unchanged. If your v2 adds a `full_name` field but the request also has `age`, `city`, and `custom_field`, those fields come through untouched. Transformers only modify the fields they explicitly touch.

---

## Contributing

Contributions are welcome! Please:

1. Fork the [repository](https://github.com/jay123anta/laravel-api-versionist)
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Write tests for your changes — all PRs must include tests
4. Run the test suite (`composer test`)
5. Submit a pull request

```bash
git clone https://github.com/jay123anta/laravel-api-versionist.git
cd laravel-api-versionist
composer install
composer test
```

---

## License

MIT. See [LICENSE](LICENSE) for details.
