<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Contracts;

use Illuminate\Http\Request;

interface VersionDetectorInterface
{
    public function detect(Request $request): ?string;
}
