<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist;

use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;

/**
 * Abstract base class for API version transformers.
 *
 * Provides sensible no-op defaults for {@see upgradeRequest()},
 * {@see downgradeResponse()}, and {@see releasedAt()} so that concrete
 * implementations only need to define what actually changes in their version.
 *
 * At minimum, subclasses must implement {@see version()} and
 * {@see description()}.
 *
 * @example
 * ```php
 * final class V2Transformer extends ApiVersionTransformer
 * {
 *     public function version(): string
 *     {
 *         return 'v2';
 *     }
 *
 *     public function description(): string
 *     {
 *         return 'Renamed "name" field to "full_name".';
 *     }
 *
 *     public function upgradeRequest(array $data): array
 *     {
 *         if (isset($data['name'])) {
 *             $data['full_name'] = $data['name'];
 *             unset($data['name']);
 *         }
 *         return $data;
 *     }
 *
 *     public function downgradeResponse(array $data): array
 *     {
 *         if (isset($data['full_name'])) {
 *             $data['name'] = $data['full_name'];
 *             unset($data['full_name']);
 *         }
 *         return $data;
 *     }
 * }
 * ```
 */
abstract class ApiVersionTransformer implements VersionTransformerInterface
{
    /**
     * {@inheritDoc}
     */
    abstract public function version(): string;

    /**
     * {@inheritDoc}
     */
    abstract public function description(): string;

    /**
     * {@inheritDoc}
     *
     * Default implementation returns the data unchanged (no-op).
     * Override in subclasses that need to transform incoming requests.
     *
     * @param  array<string, mixed>  $data  The request payload from the previous version.
     * @return array<string, mixed>         The unchanged request payload.
     */
    public function upgradeRequest(array $data): array
    {
        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * Default implementation returns the data unchanged (no-op).
     * Override in subclasses that need to transform outgoing responses.
     *
     * @param  array<string, mixed>  $data  The response payload from this version.
     * @return array<string, mixed>         The unchanged response payload.
     */
    public function downgradeResponse(array $data): array
    {
        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * Default implementation returns null (no release date set).
     * Override in subclasses to provide a release date.
     *
     * @return string|null Always null in the base implementation.
     */
    public function releasedAt(): ?string
    {
        return null;
    }
}
