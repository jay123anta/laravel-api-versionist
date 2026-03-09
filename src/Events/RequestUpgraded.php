<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Events;

use Illuminate\Http\Request;

final class RequestUpgraded
{
    public function __construct(
        public readonly Request $request,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly array $originalData,
        public readonly array $upgradedData,
    ) {
    }
}
