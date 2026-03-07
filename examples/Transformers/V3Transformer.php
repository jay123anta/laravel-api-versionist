<?php

declare(strict_types=1);

namespace App\Api\Transformers;

use Versionist\ApiVersionist\ApiVersionTransformer;

/**
 * V3 Transformer — Role normalization, status enum, and timestamp restructuring.
 *
 * This transformer handles the breaking changes introduced in API v3:
 *
 * | v2 Field                     | v3 Field             | Change Type        |
 * |------------------------------|----------------------|--------------------|
 * | role (string)                | roles (array)        | Scalar → Array     |
 * | is_active (bool)             | status (enum string) | Bool → Enum        |
 * | created_at + updated_at      | metadata.created_at  | Flatten → Nest     |
 * |                              | metadata.updated_at  |                    |
 *
 * ## Why these changes?
 *
 * - `roles` array supports users with multiple roles without a schema change.
 * - `status` enum (`active`, `inactive`, `suspended`) is richer than a boolean.
 * - `metadata` object groups audit fields, keeping the top-level object clean
 *   and extensible for future metadata (e.g. `metadata.last_login_at`).
 */
final class V3Transformer extends ApiVersionTransformer
{
    /**
     * The API version this transformer handles.
     */
    public function version(): string
    {
        return 'v3';
    }

    /**
     * Human-readable summary of what changed in this version.
     */
    public function description(): string
    {
        return 'Converted role to roles array, is_active bool to status enum, moved timestamps into metadata object.';
    }

    /**
     * The date this API version was released.
     */
    public function releasedAt(): ?string
    {
        return '2024-09-01';
    }

    /**
     * Upgrade a v2 request payload to v3 schema.
     *
     * @param  array<string, mixed>  $data  The v2 request payload.
     * @return array<string, mixed>         The v3 request payload.
     */
    public function upgradeRequest(array $data): array
    {
        // role (string) → roles (array)
        if (isset($data['role']) && is_string($data['role'])) {
            $data['roles'] = [$data['role']];
            unset($data['role']);
        }

        // is_active (bool) → status (enum string)
        if (array_key_exists('is_active', $data)) {
            $data['status'] = $data['is_active'] ? 'active' : 'inactive';
            unset($data['is_active']);
        }

        // created_at + updated_at → metadata object
        if (isset($data['created_at']) || isset($data['updated_at'])) {
            $data['metadata'] = array_merge(
                $data['metadata'] ?? [],
                array_filter([
                    'created_at' => $data['created_at'] ?? null,
                    'updated_at' => $data['updated_at'] ?? null,
                ], fn ($v) => $v !== null),
            );

            unset($data['created_at'], $data['updated_at']);
        }

        return $data;
    }

    /**
     * Downgrade a v3 response payload back to v2 schema.
     *
     * @param  array<string, mixed>  $data  The v3 response payload.
     * @return array<string, mixed>         The v2 response payload.
     */
    public function downgradeResponse(array $data): array
    {
        // roles (array) → role (string) — take the first role
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['role'] = $data['roles'][0] ?? 'user';
            unset($data['roles']);
        }

        // status (enum string) → is_active (bool)
        if (isset($data['status']) && is_string($data['status'])) {
            $data['is_active'] = $data['status'] === 'active';
            unset($data['status']);
        }

        // metadata.created_at / metadata.updated_at → top-level fields
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            if (isset($data['metadata']['created_at'])) {
                $data['created_at'] = $data['metadata']['created_at'];
            }
            if (isset($data['metadata']['updated_at'])) {
                $data['updated_at'] = $data['metadata']['updated_at'];
            }

            // Remove the metadata keys we've extracted; keep others intact.
            unset($data['metadata']['created_at'], $data['metadata']['updated_at']);

            // If metadata is now empty, remove it entirely.
            if ($data['metadata'] === []) {
                unset($data['metadata']);
            }
        }

        return $data;
    }
}
