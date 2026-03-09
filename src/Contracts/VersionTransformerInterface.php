<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Contracts;

interface VersionTransformerInterface
{
    public function version(): string;

    /** @param array<string, mixed> $data */
    public function upgradeRequest(array $data): array;

    /** @param array<string, mixed> $data */
    public function downgradeResponse(array $data): array;

    public function description(): string;

    /** ISO-8601 date or null. */
    public function releasedAt(): ?string;
}
