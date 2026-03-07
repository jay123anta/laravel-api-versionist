<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Contracts;

/**
 * Contract for API version transformers.
 *
 * Each transformer represents a single API version and knows how to
 * upgrade incoming requests from the previous version and downgrade
 * outgoing responses back to the previous version's format.
 *
 * Implement this interface (or extend the abstract base class) for
 * every API version that requires request/response transformation.
 */
interface VersionTransformerInterface
{
    /**
     * Return the version identifier this transformer handles.
     *
     * The value should be a normalized version string (e.g. "v1", "v2", "v2.1").
     * Use {@see \Versionist\ApiVersionist\Support\VersionParser::parse()} to
     * ensure the value is normalized before returning it.
     *
     * @return string The normalized version string (e.g. "v2").
     */
    public function version(): string;

    /**
     * Upgrade an incoming request payload from the previous version to this version.
     *
     * This method receives the raw request data array and must return a
     * transformed array compatible with the current version's expected input.
     *
     * @param  array<string, mixed>  $data  The request payload from the previous version.
     * @return array<string, mixed>         The upgraded request payload for this version.
     */
    public function upgradeRequest(array $data): array;

    /**
     * Downgrade an outgoing response payload from this version to the previous version.
     *
     * This method receives the response data array produced by the current
     * version and must return a transformed array compatible with the
     * previous version's expected output.
     *
     * @param  array<string, mixed>  $data  The response payload from this version.
     * @return array<string, mixed>         The downgraded response payload for the previous version.
     */
    public function downgradeResponse(array $data): array;

    /**
     * Return a human-readable description of this API version.
     *
     * Used for documentation generation, debugging, and introspection.
     *
     * @return string A brief description of what changed in this version.
     */
    public function description(): string;

    /**
     * Return the release date of this API version.
     *
     * The date should be in ISO-8601 format (e.g. "2024-01-15").
     * Return null if the release date is not applicable or unknown.
     *
     * @return string|null The ISO-8601 release date, or null.
     */
    public function releasedAt(): ?string;
}
