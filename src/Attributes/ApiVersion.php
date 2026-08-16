<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Attributes;

use Attribute;

/**
 * Declares a transformer's version metadata, as an alternative to
 * overriding version(), description(), and releasedAt().
 *
 * Example:
 *   #[ApiVersion('v2', description: 'Rename name to full_name', releasedAt: '2025-01-01')]
 *   class V2Transformer extends ApiVersionTransformer { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiVersion
{
    public function __construct(
        public readonly string $version,
        public readonly string $description = '',
        public readonly ?string $releasedAt = null,
    ) {}
}
