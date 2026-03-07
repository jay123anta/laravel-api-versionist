<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Events;

use Illuminate\Http\Request;

/**
 * Dispatched after the request payload has been upgraded through the
 * version transformation pipeline.
 *
 * Listeners can inspect the original and upgraded data for logging,
 * auditing, or triggering side effects.
 *
 * All properties are readonly — this event is an immutable snapshot
 * of the transformation that just occurred.
 */
final class RequestUpgraded
{
    /**
     * Create a new RequestUpgraded event instance.
     *
     * @param  Request              $request       The HTTP request being processed.
     * @param  string               $fromVersion   The client's original API version.
     * @param  string               $toVersion     The server's current API version.
     * @param  array<string, mixed> $originalData  The request payload before transformation.
     * @param  array<string, mixed> $upgradedData  The request payload after transformation.
     */
    public function __construct(
        public readonly Request $request,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly array $originalData,
        public readonly array $upgradedData,
    ) {
    }
}
