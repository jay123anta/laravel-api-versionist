<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Events;

use Illuminate\Http\JsonResponse;

/**
 * Dispatched after the response payload has been downgraded through the
 * version transformation pipeline.
 *
 * Listeners can inspect the original and downgraded data for logging,
 * auditing, or triggering side effects.
 *
 * All properties are readonly — this event is an immutable snapshot
 * of the transformation that just occurred.
 */
final class ResponseDowngraded
{
    /**
     * Create a new ResponseDowngraded event instance.
     *
     * @param  JsonResponse         $response       The JSON response being processed.
     * @param  string               $fromVersion    The server's current API version.
     * @param  string               $toVersion      The client's requested API version.
     * @param  array<string, mixed> $originalData   The response payload before transformation.
     * @param  array<string, mixed> $downgradedData The response payload after transformation.
     */
    public function __construct(
        public readonly JsonResponse $response,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly array $originalData,
        public readonly array $downgradedData,
    ) {
    }
}
