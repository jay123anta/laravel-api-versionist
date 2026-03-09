<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Pipeline;

use Versionist\ApiVersionist\Registry\TransformerRegistry;

/**
 * Walks a downgrade chain and applies each transformer's downgradeResponse()
 * to progressively transform a response payload from a newer version back
 * to an older version.
 *
 * This pipeline is **pure** — it has no side effects, no Laravel
 * dependencies, and operates exclusively on plain arrays.
 *
 * ## How it works
 *
 * Given a response produced at v3 for a client expecting v1:
 *
 * ```
 * v3 data ──[V3::downgradeResponse()]──▶ v2 data ──[V2::downgradeResponse()]──▶ v1 data
 * ```
 *
 * The registry's getDowngradeChain(v3, v1) returns [V3Transformer, V2Transformer].
 * This pipeline iterates through that chain, feeding each transformer's output
 * into the next transformer's input (a reduce/fold operation).
 */
final class ResponseDowngradePipeline
{
    /**
     * Create a new ResponseDowngradePipeline instance.
     *
     * @param  TransformerRegistry  $registry  The transformer registry to resolve chains from.
     */
    public function __construct(
        private readonly TransformerRegistry $registry,
    ) {
    }

    /**
     * Run the downgrade pipeline on a response payload.
     *
     * Walks the downgrade chain from $from to $to, calling downgradeResponse()
     * on each transformer in descending version order. Each transformer
     * receives the output of the previous one.
     *
     * If $from and $to are the same version, the data is returned unchanged.
     *
     * @param  array<string, mixed>  $data  The original response payload.
     * @param  string                $from  The server's current API version (highest).
     * @param  string                $to    The client's requested API version (lowest).
     * @return array<string, mixed>         The downgraded response payload.
     *
     * @throws \Versionist\ApiVersionist\Exceptions\UnknownVersionException
     *         If either version is not known to the registry.
     */
    public function run(array $data, string $from, string $to): array
    {
        $chain = $this->registry->getDowngradeChain($from, $to);

        // Fold: pipe the data through each transformer in descending order.
        // Each step transforms the payload one version backward.
        foreach ($chain as $transformer) {
            try {
                $data = $transformer->downgradeResponse($data);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    sprintf(
                        'Transformer %s::downgradeResponse() failed: %s',
                        $transformer::class,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        return $data;
    }
}
