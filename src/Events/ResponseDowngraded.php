<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Events;

use Illuminate\Http\JsonResponse;

final class ResponseDowngraded
{
    public function __construct(
        public readonly JsonResponse $response,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly array $originalData,
        public readonly array $downgradedData,
    ) {
    }
}
