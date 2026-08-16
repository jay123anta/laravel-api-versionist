<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Versionist\ApiVersionist\Support\ChangelogBuilder;

/**
 * Serves the machine-readable version changelog advertised by the
 * Link: rel="successor-version" deprecation header.
 */
final class ChangelogController
{
    public function __invoke(ChangelogBuilder $builder): JsonResponse
    {
        return new JsonResponse($builder->build());
    }
}
