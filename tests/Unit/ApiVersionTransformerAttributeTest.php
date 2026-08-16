<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Versionist\ApiVersionist\ApiVersionTransformer;
use Versionist\ApiVersionist\Attributes\ApiVersion;
use Versionist\ApiVersionist\Exceptions\InvalidTransformerException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;

#[ApiVersion('v5', description: 'Parent-declared metadata')]
class AttributedParentTransformer extends ApiVersionTransformer {}

class InheritingChildTransformer extends AttributedParentTransformer {}

final class ApiVersionTransformerAttributeTest extends TestCase
{
    #[Test]
    public function attribute_supplies_version_description_and_released_at(): void
    {
        $transformer = new #[ApiVersion('v2', description: 'Rename name to full_name', releasedAt: '2025-01-01')] class extends ApiVersionTransformer {};

        $this->assertSame('v2', $transformer->version());
        $this->assertSame('Rename name to full_name', $transformer->description());
        $this->assertSame('2025-01-01', $transformer->releasedAt());
    }

    #[Test]
    public function attribute_defaults_to_empty_description_and_null_released_at(): void
    {
        $transformer = new #[ApiVersion('v3')] class extends ApiVersionTransformer {};

        $this->assertSame('v3', $transformer->version());
        $this->assertSame('', $transformer->description());
        $this->assertNull($transformer->releasedAt());
    }

    #[Test]
    public function method_override_wins_over_attribute(): void
    {
        $transformer = new #[ApiVersion('v2', description: 'From attribute')] class extends ApiVersionTransformer
        {
            public function version(): string
            {
                return 'v9';
            }

            public function description(): string
            {
                return 'From override';
            }
        };

        $this->assertSame('v9', $transformer->version());
        $this->assertSame('From override', $transformer->description());
    }

    #[Test]
    public function version_throws_without_attribute_or_override(): void
    {
        $transformer = new class extends ApiVersionTransformer {};

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/must either override version\(\) or declare a #\[ApiVersion\] attribute/');

        $transformer->version();
    }

    #[Test]
    public function subclasses_inherit_the_parent_class_attribute(): void
    {
        $transformer = new InheritingChildTransformer;

        $this->assertSame('v5', $transformer->version());
        $this->assertSame('Parent-declared metadata', $transformer->description());
    }

    #[Test]
    public function registry_wraps_missing_metadata_in_invalid_transformer_exception(): void
    {
        $registry = new TransformerRegistry;

        $this->expectException(InvalidTransformerException::class);
        $this->expectExceptionMessageMatches('/must either override version\(\)/');

        $registry->register(new class extends ApiVersionTransformer {});
    }

    #[Test]
    public function description_and_released_at_have_safe_defaults_without_attribute(): void
    {
        $transformer = new class extends ApiVersionTransformer
        {
            public function version(): string
            {
                return 'v2';
            }
        };

        $this->assertSame('', $transformer->description());
        $this->assertNull($transformer->releasedAt());
    }
}
