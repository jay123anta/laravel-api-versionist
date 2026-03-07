<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Versionist\ApiVersionist\Pipeline\RequestUpgradePipeline;
use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Tests\TestCase;

final class RequestUpgradePipelineTest extends TestCase
{
    private TransformerRegistry $registry;
    private RequestUpgradePipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new TransformerRegistry();
        $this->pipeline = new RequestUpgradePipeline($this->registry);
    }

    #[Test]
    public function it_applies_transformers_in_ascending_order(): void
    {
        $this->registry->registerMany([
            $this->makeTransformer('v2', upgrade: function (array $data): array {
                $data['step_v2'] = true;
                return $data;
            }),
            $this->makeTransformer('v3', upgrade: function (array $data): array {
                $data['step_v3'] = true;
                return $data;
            }),
        ]);

        $result = $this->pipeline->run(['name' => 'test'], 'v1', 'v3');

        $this->assertSame([
            'name' => 'test',
            'step_v2' => true,
            'step_v3' => true,
        ], $result);
    }

    #[Test]
    public function it_chains_data_through_each_transformer(): void
    {
        // V2 renames 'name' to 'full_name'
        $this->registry->register($this->makeTransformer('v2', upgrade: function (array $data): array {
            $data['full_name'] = $data['name'];
            unset($data['name']);
            return $data;
        }));

        // V3 wraps 'email' into 'contact.email'
        $this->registry->register($this->makeTransformer('v3', upgrade: function (array $data): array {
            $data['contact'] = ['email' => $data['email']];
            unset($data['email']);
            return $data;
        }));

        $input = ['name' => 'Alice', 'email' => 'alice@test.com'];
        $result = $this->pipeline->run($input, 'v1', 'v3');

        $this->assertSame([
            'full_name' => 'Alice',
            'contact' => ['email' => 'alice@test.com'],
        ], $result);
    }

    #[Test]
    public function it_returns_data_unchanged_for_same_version(): void
    {
        $this->registry->register($this->makeTransformer('v2', upgrade: function (array $data): array {
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
        $this->registry->registerMany([
            $this->makeTransformer('v2', upgrade: fn(array $d) => array_merge($d, ['v2' => true])),
            $this->makeTransformer('v3', upgrade: fn(array $d) => array_merge($d, ['v3' => true])),
            $this->makeTransformer('v4', upgrade: fn(array $d) => array_merge($d, ['v4' => true])),
        ]);

        $result = $this->pipeline->run([], 'v2', 'v4');

        $this->assertArrayNotHasKey('v2', $result);
        $this->assertTrue($result['v3']);
        $this->assertTrue($result['v4']);
    }

    #[Test]
    public function it_handles_single_step_upgrade(): void
    {
        $this->registry->register($this->makeTransformer('v2', upgrade: function (array $data): array {
            $data['upgraded'] = true;
            return $data;
        }));

        $result = $this->pipeline->run(['id' => 1], 'v1', 'v2');

        $this->assertSame(['id' => 1, 'upgraded' => true], $result);
    }
}
