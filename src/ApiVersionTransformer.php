<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist;

use Versionist\ApiVersionist\Attributes\ApiVersion;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;

/**
 * Base class with no-op defaults for upgradeRequest(), downgradeResponse(), and releasedAt().
 *
 * Subclasses declare their version metadata either by overriding version(),
 * description(), and releasedAt(), or with the #[ApiVersion] class attribute.
 */
abstract class ApiVersionTransformer implements VersionTransformerInterface
{
    public function version(): string
    {
        $attribute = $this->apiVersionAttribute();

        if ($attribute === null) {
            throw new \LogicException(sprintf(
                '%s must either override version() or declare a #[ApiVersion] attribute.',
                static::class,
            ));
        }

        return $attribute->version;
    }

    public function description(): string
    {
        $attribute = $this->apiVersionAttribute();

        return $attribute !== null ? $attribute->description : '';
    }

    public function releasedAt(): ?string
    {
        return $this->apiVersionAttribute()?->releasedAt;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upgradeRequest(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function downgradeResponse(array $data): array
    {
        return $data;
    }

    /** @var array<string, ApiVersion|null> */
    private static array $attributeCache = [];

    /**
     * Resolves the #[ApiVersion] attribute for this class, walking up the
     * inheritance chain so subclasses inherit their parent's metadata.
     * Memoized per class — the attribute is immutable at runtime.
     */
    private function apiVersionAttribute(): ?ApiVersion
    {
        if (array_key_exists(static::class, self::$attributeCache)) {
            return self::$attributeCache[static::class];
        }

        $resolved = null;

        for ($class = new \ReflectionClass(static::class); $class !== false; $class = $class->getParentClass()) {
            $attributes = $class->getAttributes(ApiVersion::class);

            if ($attributes !== []) {
                $resolved = $attributes[0]->newInstance();
                break;
            }
        }

        return self::$attributeCache[static::class] = $resolved;
    }
}
