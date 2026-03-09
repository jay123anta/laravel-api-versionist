# Laravel API Versionist

**Your API changes. Your controllers don't.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayanta/laravel-api-versionist)](https://packagist.org/packages/jayanta/laravel-api-versionist)
[![PHP Version](https://img.shields.io/packagist/php-v/jayanta/laravel-api-versionist)](https://packagist.org/packages/jayanta/laravel-api-versionist)
[![Tests](https://github.com/jay123anta/laravel-api-versionist/actions/workflows/tests.yml/badge.svg)](https://github.com/jay123anta/laravel-api-versionist/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/jayanta/laravel-api-versionist)](https://packagist.org/packages/jayanta/laravel-api-versionist)
[![Total Downloads](https://img.shields.io/packagist/dt/jayanta/laravel-api-versionist)](https://packagist.org/packages/jayanta/laravel-api-versionist)

Inspired by [Stripe's API versioning architecture](https://stripe.com/blog/api-versioning) — you write small transformer classes that describe what changed between versions. The package upgrades old requests and downgrades new responses automatically. Your controllers always speak the latest version.

Supports Laravel 10, 11 & 12 · PHP 8.1+

---

## The problem, in short

You shipped v1. A mobile app depends on it. Now you need v2 with different field names. Your options are: duplicate every controller per version (bug fixes applied N times), scatter `if/else` version checks everywhere, or use this package — one controller, one version, transformers handle the rest.

```php
// Your controller. Always latest version. That's it.
class UserController extends Controller
{
    public function show(User $user)
    {
        return response()->json([
            'handle' => $user->handle,
            'roles' => $user->roles,
        ]);
    }
}
```

A v1 client hits this endpoint and gets back `username`, `role` — automatically downgraded by the transformer you wrote once.

---

## How it works

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

Old request comes in → upgraded to latest → controller processes it → response downgraded back to the client's version. Your controller never changes.

---

## Installation

```bash
composer require jayanta/laravel-api-versionist
php artisan vendor:publish --tag=api-versionist-config
```

Laravel auto-discovers the service provider. No manual registration.

---

## Quick start

**1. Generate a transformer:**

```bash
php artisan api:make-transformer v2
```

**2. Define what changed:**

```php
// app/Api/Transformers/V2Transformer.php

final class V2Transformer extends ApiVersionTransformer
{
    public function version(): string { return 'v2'; }

    public function description(): string
    {
        return 'Renamed username to handle, converted role string to roles array.';
    }

    public function upgradeRequest(array $data): array
    {
        if (isset($data['username'])) {
            $data['handle'] = $data['username'];
            unset($data['username']);
        }

        if (isset($data['role']) && is_string($data['role'])) {
            $data['roles'] = [$data['role']];
            unset($data['role']);
        }

        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        if (isset($data['handle'])) {
            $data['username'] = $data['handle'];
            unset($data['handle']);
        }

        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['role'] = $data['roles'][0] ?? 'user';
            unset($data['roles']);
        }

        return $data;
    }
}
```

**3. Register it:**

```php
// config/api-versionist.php
'latest_version' => 'v2',
'transformers' => [
    App\Api\Transformers\V2Transformer::class,
],
'response_data_key' => null, // null for flat JSON, 'data' for wrapped responses
```

If you leave `latest_version` as `'v1'` after adding a V2Transformer, no transformers will run. Always update this value.

**4. Add middleware:**

```php
// routes/api.php
Route::middleware('api.version')->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show']);
});

// or use the shorthand macro
Route::versioned()->group(function () { /* ... */ });
```

**5. Test it:**

```bash
curl -H "X-Api-Version: v2" http://your-app.test/api/users/1
# → {"handle": "janedoe", "roles": ["admin"]}

curl -H "X-Api-Version: v1" http://your-app.test/api/users/1
# → {"username": "janedoe", "role": "admin"}
```

---

## Transformers in depth

Each transformer describes one version transition. A V2Transformer handles v1→v2. A V3Transformer handles v2→v3. The package chains them automatically.

```php
final class V2Transformer extends ApiVersionTransformer
{
    public function version(): string { return 'v2'; }
    public function description(): string { return 'Renamed username to handle, role string to roles array.'; }
    public function releasedAt(): ?string { return '2025-03-01'; }

    public function upgradeRequest(array $data): array
    {
        if (isset($data['username'])) {
            $data['handle'] = $data['username'];
            unset($data['username']);
        }

        if (isset($data['role']) && is_string($data['role'])) {
            $data['roles'] = [$data['role']];
            unset($data['role']);
        }

        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        if (isset($data['handle'])) {
            $data['username'] = $data['handle'];
            unset($data['handle']);
        }

        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['role'] = $data['roles'][0] ?? 'user';
            unset($data['roles']);
        }

        return $data;
    }
}
```

Fields you don't touch pass through unchanged. A request with `age`, `city`, `custom_field` keeps all of them — transformers only modify what they explicitly reference.

### Constructor injection

Transformers are resolved through Laravel's service container, so DI works:

```php
final class V3Transformer extends ApiVersionTransformer
{
    public function __construct(
        private readonly UserRepository $repo
    ) {}

    public function version(): string { return 'v3'; }
    public function description(): string { return 'Added legacy_role lookup for v2 clients.'; }

    public function downgradeResponse(array $data): array
    {
        if (isset($data['user_id'])) {
            $data['legacy_role'] = $this->repo->getLegacyRole($data['user_id']);
        }
        return $data;
    }
}
```

Keep DB access in transformers read-only and lightweight. If a transformer needs heavy queries, the version gap is probably too wide for this pattern.

---

## When NOT to use transformers

Transformers handle **data shape changes** — field renames, restructures, type conversions.

For behavior changes, use the request macros the package provides:

```php
if ($request->isApiVersionAtLeast('v3')) {
    // new pricing logic, auth behavior, business rules
}
```

Transformers = structure. Application code = behavior. If your breaking change is behavioral, separate controllers are probably cleaner.

---

## Multi-version example

Three versions, showing the full upgrade/downgrade chain:

| Version | Fields |
|---------|--------|
| v1 | `username`, `role`, `is_active` |
| v2 | `handle`, `role`, `is_active` |
| v3 | `handle`, `roles[]`, `status` |

A v1 client sends: `{ "username": "janedoe", "role": "admin", "is_active": true }`

**Upgrade chain:**
1. V2Transformer: `username` → `handle`
2. V3Transformer: `role` → `roles[]`, `is_active` → `status`
3. Controller receives: `{ "handle": "janedoe", "roles": ["admin"], "status": "active" }`

**Downgrade chain** (response goes back through V3 then V2):
1. V3Transformer: `roles[]` → `role`, `status` → `is_active`
2. V2Transformer: `handle` → `username`
3. v1 client receives: `{ "username": "janedoe", "role": "admin", "is_active": true }`

---

## Version detection

Four strategies, tried in config order. First match wins.

| Strategy | Example |
|---|---|
| `url_prefix` | `GET /api/v2/users` |
| `header` | `X-Api-Version: v2` |
| `query_param` | `GET /api/users?version=v2` |
| `accept_header` | `Accept: application/vnd.api+json;version=2` |

```php
'detection_strategies' => ['url_prefix', 'header', 'query_param'],
```

No version detected → falls back to `default_version` (usually `'v1'`). Unknown version in strict mode → HTTP 400.

---

## Configuration reference

| Key | Default | Description |
|---|---|---|
| `default_version` | `'v1'` | Fallback when no version detected |
| `latest_version` | `'v1'` | Must match your highest transformer |
| `transformers` | `[]` | Array of transformer class names |
| `deprecated_versions` | `[]` | Version → sunset date map (`'v1' => '2025-12-31'`) |
| `strict_mode` | `false` | `true` = unknown versions throw HTTP 400 |
| `response_data_key` | `'data'` | Key to transform in responses. `null` = entire body |
| `request_data_key` | `null` | Key to transform in requests. `null` = entire body |
| `add_version_headers` | `true` | Adds `X-Api-Version` and `X-Api-Latest-Version` headers |
| `detection_strategies` | `[...]` | Ordered list of detection strategies |
| `header_name` | `'X-Api-Version'` | Header name for `header` strategy |
| `query_param` | `'version'` | Param name for `query_param` strategy |
| `changelog.enabled` | `false` | Expose version metadata as JSON endpoint |
| `changelog.endpoint` | `'/api/versions'` | Changelog URL path |

**Important:** The default `response_data_key` is `'data'`. If your controller returns flat JSON (not wrapped in `{"data": {...}}`), set this to `null` or transformers won't touch the response.

---

## Response headers

With `add_version_headers` enabled:

```
X-Api-Version: v2
X-Api-Latest-Version: v3
```

For deprecated versions:

```
Deprecation: true
Sunset: 2025-12-31
Link: </api/versions>; rel="successor-version"
```

Mark versions as deprecated in config:

```php
'deprecated_versions' => [
    'v1' => '2025-12-31',
    'v2' => null,  // deprecated, no sunset date yet
],
```

Headers follow [RFC 8594](https://tools.ietf.org/html/rfc8594). Deprecated versions still work normally.

---

## Request macros

After the middleware runs, every `Request` gets these:

```php
$request->apiVersion();              // "v2"
$request->isApiVersion('v2');        // true
$request->isApiVersionAtLeast('v2'); // true for v2, v3, v4...
$request->isApiVersionBefore('v3');  // true for v1, v2
```

Useful for version-specific behavior that doesn't belong in transformers:

```php
if ($request->isApiVersionAtLeast('v3')) {
    $users->each(fn ($user) => $user->append('login_streak'));
}
```

---

## Artisan commands

```bash
# Scaffold a new transformer
php artisan api:make-transformer v4

# List all registered versions with status
php artisan api:versions
php artisan api:versions --chains  # show upgrade/downgrade chains

# Human-readable changelog
php artisan api:changelog
php artisan api:changelog --format=json

# Validate transformers and dry-run the pipeline
php artisan api:audit --from=v1 --to=v3
```

---

## Envelope mode

If your API wraps responses in `{"data": {...}, "meta": {...}}`, set `response_data_key` to `'data'` so transformers only touch the data portion:

```php
'response_data_key' => 'data',
'request_data_key' => 'data',
```

Only `"data"` gets transformed. `"meta"`, `"links"`, pagination — all untouched.

If your controller returns flat JSON, set both to `null`.

---

## Known limitations

**Flat array responses** — If you return `[{...}, {...}]` (a list of objects), the transformer sees the array, not individual items. Wrap lists in `{"data": [...]}` and use `response_data_key => 'data'`.

**Nested keys** — The package transforms one location (top-level or `response_data_key`). It won't recursively walk `{"user": {...}, "company": {...}}`. Keep transformable data at one level.

**Lossy transforms** — Some field changes can't be perfectly reversed. Converting an array to a scalar (e.g. `roles[]` → `role`) drops extra elements. Design transforms that round-trip cleanly, or accept the data loss and document it.

### When separate controllers are the better choice

This pattern works best for **structural changes** — field renames, payload reshaping, type conversions.

Consider separate controllers when:
- Changes are behavioral, not structural (different auth, pricing, business rules)
- Version differences are so large that transformers become hard to follow
- You're maintaining very old legacy systems where scattered logic makes debugging harder

No universally correct approach. Transformers reduce duplication for structural versioning. Separate controllers give clearer isolation when behavior diverges.

---

## Testing

Transformers are plain PHP — test them directly:

```php
class V2TransformerTest extends TestCase
{
    private V2Transformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new V2Transformer();
    }

    public function test_upgrade_renames_username_to_handle(): void
    {
        $result = $this->transformer->upgradeRequest([
            'username' => 'janedoe',
            'role' => 'admin',
        ]);

        $this->assertSame('janedoe', $result['handle']);
        $this->assertSame(['admin'], $result['roles']);
        $this->assertArrayNotHasKey('username', $result);
    }

    public function test_round_trip_preserves_data(): void
    {
        $original = ['username' => 'janedoe', 'role' => 'admin'];
        $upgraded = $this->transformer->upgradeRequest($original);
        $downgraded = $this->transformer->downgradeResponse($upgraded);

        $this->assertSame($original, $downgraded);
    }
}
```

For transformers with constructor injection, mock the dependency:

```php
public function test_v3_downgrade_adds_legacy_role(): void
{
    $repo = $this->createMock(UserRepository::class);
    $repo->method('getLegacyRole')->with(42)->willReturn('editor');

    $transformer = new V3Transformer($repo);

    $result = $transformer->downgradeResponse(['user_id' => 42, 'handle' => 'jane']);
    $this->assertSame('editor', $result['legacy_role']);
}
```

Or validate everything at once: `php artisan api:audit`

---

## FAQ

**Do I need to change my controllers for every version?**
No. Controllers always return the latest version. You write one transformer per version step.

**What if v1 and v3 are completely different?**
Write one transformer per step. V2Transformer handles v1→v2, V3Transformer handles v2→v3. The package chains them. You never write a combined v1→v3 transformer.

**Can I use date-based versions like `2024-01-15`?**
Yes. The parser accepts both numeric (`v1`, `v2.1`) and date-based (`2024-01-15`) formats. Dates are compared chronologically. You can even mix them — date versions sort higher than numeric ones.

---

## Prior art

This pattern was [publicly documented by Stripe in 2017](https://stripe.com/blog/api-versioning) (Brandur Leach), adopted by [Intercom in 2018](https://www.intercom.com/blog/api-versioning/), and open-sourced for Ruby by [Keygen](https://github.com/keygen-sh/request_migrations). This package brings the same idea to Laravel.

---

## Credits

- [Jay Anta](https://github.com/jay123anta)

---

## Contributing

Contributions welcome:

1. Fork the [repository](https://github.com/jay123anta/laravel-api-versionist)
2. Create a feature branch
3. Write tests — all PRs must include tests
4. Run `composer test`
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
