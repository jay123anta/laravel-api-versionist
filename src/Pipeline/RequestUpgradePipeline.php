<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Pipeline;

use Versionist\ApiVersionist\Registry\TransformerRegistry;

/**
 * Walks an upgrade chain and applies each transformer's upgradeRequest()
 * to progressively transform a request payload from an older version to
 * a newer version.
 *
 * This pipeline is **pure** — it has no side effects, no Laravel
 * dependencies, and operates exclusively on plain arrays.
 *
 * ## How it works
 *
 * Given a request sent by a client on v1 to an API currently at v3:
 *
 * ```
 * v1 data ──[V2::upgradeRequest()]──▶ v2 data ──[V3::upgradeRequest()]──▶ v3 data
 * ```
 *
 * The registry's getUpgradeChain(v1, v3) returns [V2Transformer, V3Transformer].
 * This pipeline iterates through that chain, feeding each transformer's output
 * into the next transformer's input (a reduce/fold operation).
 */
final class RequestUpgradePipeline
{
    /**
     * Create a new RequestUpgradePipeline instance.
     *
     * @param  TransformerRegistry  $registry  The transformer registry to resolve chains from.
     */
    public function __construct(
        private readonly TransformerRegistry $registry,
    ) {
    }

    /**
     * Run the upgrade pipeline on a request payload.
     *
     * Walks the upgrade chain from $from to $to, calling upgradeRequest()
     * on each transformer in ascending version order. Each transformer
     * receives the output of the previous one.
     *
     * If $from and $to are the same version, the data is returned unchanged.
     *
     * @param  array<string, mixed>  $data  The original request payload.
     * @param  string                $from  The client's API version.
     * @param  string                $to    The server's current API version.
     * @return array<string, mixed>         The upgraded request payload.
     *
     * @throws \Versionist\ApiVersionist\Exceptions\UnknownVersionException
     *         If either version is not known to the registry.
     */
    public function run(array $data, string $from, string $to): array
    {
        $chain = $this->registry->getUpgradeChain($from, $to);

        // Fold: pipe the data through each transformer in ascending order.
        // Each step transforms the payload one version forward.
        foreach ($chain as $transformer) {
            try {
                $data = $transformer->upgradeRequest($data);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    sprintf(
                        'Transformer %s::upgradeRequest() failed: %s',
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
