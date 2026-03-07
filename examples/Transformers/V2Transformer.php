<?php

declare(strict_types=1);

namespace App\Api\Transformers;

use Versionist\ApiVersionist\ApiVersionTransformer;

/**
 * V2 Transformer — User profile field restructuring.
 *
 * This transformer handles the breaking changes introduced in API v2:
 *
 * | v1 Field                   | v2 Field       | Change Type        |
 * |----------------------------|----------------|--------------------|
 * | first_name + last_name     | full_name      | Merge              |
 * | username                   | handle         | Rename             |
 * | profile_image              | avatar_url     | Rename             |
 *
 * ## Why these changes?
 *
 * - `full_name` eliminates the guesswork of name concatenation for display.
 * - `handle` aligns with OAuth/social platform terminology.
 * - `avatar_url` makes it clear the value is a URL, not a blob or path.
 */
final class V2Transformer extends ApiVersionTransformer
{
    /**
     * The API version this transformer handles.
     */
    public function version(): string
    {
        return 'v2';
    }

    /**
     * Human-readable summary of what changed in this version.
     */
    public function description(): string
    {
        return 'Merged first_name + last_name into full_name, renamed username to handle, renamed profile_image to avatar_url.';
    }

    /**
     * The date this API version was released.
     */
    public function releasedAt(): ?string
    {
        return '2024-03-15';
    }

    /**
     * Upgrade a v1 request payload to v2 schema.
     *
     * Called when a v1 client sends a request to an API running v2+.
     * The controller will receive v2-shaped data.
     *
     * @param  array<string, mixed>  $data  The v1 request payload.
     * @return array<string, mixed>         The v2 request payload.
     */
    public function upgradeRequest(array $data): array
    {
        // Merge first_name + last_name → full_name
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $first = $data['first_name'] ?? '';
            $last  = $data['last_name'] ?? '';

            $data['full_name'] = trim("{$first} {$last}");

            unset($data['first_name'], $data['last_name']);
        }

        // Rename username → handle
        if (array_key_exists('username', $data)) {
            $data['handle'] = $data['username'];
            unset($data['username']);
        }

        // Rename profile_image → avatar_url
        if (array_key_exists('profile_image', $data)) {
            $data['avatar_url'] = $data['profile_image'];
            unset($data['profile_image']);
        }

        return $data;
    }

    /**
     * Downgrade a v2 response payload back to v1 schema.
     *
     * Called when a v1 client receives a response from an API running v2+.
     * The client will receive v1-shaped data it understands.
     *
     * @param  array<string, mixed>  $data  The v2 response payload.
     * @return array<string, mixed>         The v1 response payload.
     */
    public function downgradeResponse(array $data): array
    {
        // Split full_name → first_name + last_name
        if (isset($data['full_name'])) {
            $parts = explode(' ', $data['full_name'], 2);

            $data['first_name'] = $parts[0];
            $data['last_name']  = $parts[1] ?? '';

            unset($data['full_name']);
        }

        // Rename handle → username
        if (array_key_exists('handle', $data)) {
            $data['username'] = $data['handle'];
            unset($data['handle']);
        }

        // Rename avatar_url → profile_image
        if (array_key_exists('avatar_url', $data)) {
            $data['profile_image'] = $data['avatar_url'];
            unset($data['avatar_url']);
        }

        return $data;
    }
}
