<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Exceptions\VersionDowngradeException;
use Versionist\ApiVersionist\Pipeline\ResponseDowngradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Tests\TestCase;

final class ResponseDowngradePipelineTest extends TestCase
{
    private TransformerRegistry $registry;
    private ResponseDowngradePipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new TransformerRegistry();
        $this->pipeline = new ResponseDowngradePipeline($this->registry);
    }

    #[Test]
    public function it_applies_transformers_in_descending_order(): void
    {
        $log = [];

        $this->registry->registerMany([
            $this->makeTransformer('v2', downgrade: function (array $data) use (&$log): array {
                $log[] = 'v2';
                return $data;
            }),
            $this->makeTransformer('v3', downgrade: function (array $data) use (&$log): array {
                $log[] = 'v3';
                return $data;
            }),
        ]);

        $this->pipeline->run(['test' => true], 'v3', 'v1');

        // v3 downgrade should run first, then v2
        $this->assertSame(['v3', 'v2'], $log);
    }

    #[Test]
    public function it_chains_data_through_each_transformer_in_reverse(): void
    {
        // V3 unwraps 'contact.email' back to 'email'
        $this->registry->register($this->makeTransformer('v3', downgrade: function (array $data): array {
            $data['email'] = $data['contact']['email'];
            unset($data['contact']);
            return $data;
        }));

        // V2 renames 'full_name' back to 'name'
        $this->registry->register($this->makeTransformer('v2', downgrade: function (array $data): array {
            $data['name'] = $data['full_name'];
            unset($data['full_name']);
            return $data;
        }));

        $input = [
            'full_name' => 'Alice',
            'contact' => ['email' => 'alice@test.com'],
        ];

        $result = $this->pipeline->run($input, 'v3', 'v1');

        $this->assertSame('Alice', $result['name']);
        $this->assertSame('alice@test.com', $result['email']);
        $this->assertArrayNotHasKey('full_name', $result);
        $this->assertArrayNotHasKey('contact', $result);
    }

    #[Test]
    public function it_returns_data_unchanged_for_same_version(): void
    {
        $this->registry->register($this->makeTransformer('v2', downgrade: function (array $data): array {
            $data['should_not_run'] = true;
            return $data;
        }));

        $input = ['name' => 'test'];
        $result = $this->pipeline->run($input, 'v2', 'v2');

        $this->assertSame($input, $result);
    }

    #[Test]
    public function it_runs_only_relevant_transformers_in_partial_chain(): void
    {
        $log = [];

        $this->registry->registerMany([
            $this->makeTransformer('v2', downgrade: function (array $data) use (&$log): array {
                $log[] = 'v2';
                return $data;
            }),
            $this->makeTransformer('v3', downgrade: function (array $data) use (&$log): array {
                $log[] = 'v3';
                return $data;
            }),
            $this->makeTransformer('v4', downgrade: function (array $data) use (&$log): array {
                $log[] = 'v4';
                return $data;
            }),
        ]);

        $this->pipeline->run([], 'v4', 'v2');

        // Only v4 and v3 should run (not v2 — we're stopping at v2)
        $this->assertSame(['v4', 'v3'], $log);
    }

    #[Test]
    public function it_handles_single_step_downgrade(): void
    {
        $this->registry->register($this->makeTransformer('v2', downgrade: function (array $data): array {
            $data['downgraded'] = true;
            return $data;
        }));

        $result = $this->pipeline->run(['id' => 1], 'v2', 'v1');

        $this->assertSame(['id' => 1, 'downgraded' => true], $result);
    }

    #[Test]
    public function it_wraps_transformer_exception_with_class_name(): void
    {
        $this->registry->register($this->makeTransformer('v2', downgrade: function (array $data): array {
            throw new \InvalidArgumentException('corrupt data');
        }));

        $this->expectException(VersionDowngradeException::class);
        $this->expectExceptionMessageMatches('/downgradeResponse\(\) failed: corrupt data/');

        $this->pipeline->run(['id' => 1], 'v2', 'v1');
    }

    #[Test]
    public function it_preserves_original_exception_as_previous(): void
    {
        $original = new \LogicException('original error');

        $this->registry->register($this->makeTransformer('v2', downgrade: function (array $data) use ($original): array {
            throw $original;
        }));

        try {
            $this->pipeline->run([], 'v2', 'v1');
            $this->fail('Expected VersionDowngradeException');
        } catch (VersionDowngradeException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame('v2', $e->fromVersion);
            $this->assertSame('v1', $e->toVersion);
        }
    }
}
