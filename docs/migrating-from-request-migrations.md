# Migrating from tomschlick/request-migrations

`request-migrations` pioneered Stripe-style versioning for Laravel but has been
dormant for years and supports neither modern Laravel nor modern PHP. Versionist
implements the same request/response transformation model, so migration is
mostly mechanical.

## Concept mapping

| request-migrations | Versionist |
|---|---|
| `RequestMigration` class | Transformer extending `ApiVersionTransformer` |
| `migrateRequest()` | `upgradeRequest(array $data): array` |
| `migrateResponse()` | `downgradeResponse(array $data): array` |
| `config/request-migrations.php` version map | `config/api-versionist.php` `transformers` list |
| `X-Api-Request-Version` header | Any strategy: URL prefix, `X-Api-Version` header, Accept header, or query param |
| `\Migrations\` namespace convention | Anywhere autoloadable — register the class name |

## Step by step

1. **Install:** `composer require jayanta/laravel-api-versionist` and publish the
   config: `php artisan vendor:publish --tag=api-versionist-config`.
2. **Convert each migration class** to a transformer. A `request-migrations`
   class for "v2 renamed `name` to `full_name`" becomes:

   ```php
   use Versionist\ApiVersionist\ApiVersionTransformer;
   use Versionist\ApiVersionist\Attributes\ApiVersion;

   #[ApiVersion('v2', description: 'Renamed name to full_name')]
   final class V2Transformer extends ApiVersionTransformer
   {
       public function upgradeRequest(array $data): array
       {
           if (isset($data['name'])) {
               $data['full_name'] = $data['name'];
               unset($data['name']);
           }

           return $data;
       }

       public function downgradeResponse(array $data): array
       {
           if (isset($data['full_name'])) {
               $data['name'] = $data['full_name'];
               unset($data['full_name']);
           }

           return $data;
       }
   }
   ```

3. **Register transformers** in `config/api-versionist.php` under
   `transformers`, and set `latest_version`.
4. **Swap the middleware.** Replace the `request-migrations` middleware with
   `api.version` (or use the `Route::versioned()` macro).
5. **Keep your header name.** Set `header_name` to `X-Api-Request-Version` in
   the config if your clients already send it.
6. **Verify:** `php artisan api:audit` dry-runs every transformer chain and
   flags no-op or throwing transformers before you deploy.

## What you gain

- Laravel 10–13, PHP 8.1–8.5 support with an actively tested CI matrix
- RFC 8594/9745 `Deprecation`, `Sunset`, and `Link` headers for retiring versions
- A JSON changelog endpoint clients can discover through the `Link` header
- `api:versions`, `api:changelog`, `api:make-transformer`, `api:audit` tooling
