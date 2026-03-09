<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist;

use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;

/**
 * Base class with no-op defaults for upgradeRequest(), downgradeResponse(), and releasedAt().
 * Subclasses must implement version() and description().
 */
abstract class ApiVersionTransformer implements VersionTransformerInterface
{
    abstract public function version(): string;

    abstract public function description(): string;

    public function upgradeRequest(array $data): array
    {
        return $data;
    }

    public function downgradeResponse(array $data): array
    {
        return $data;
    }

    public function releasedAt(): ?string
    {
        return null;
    }
}
