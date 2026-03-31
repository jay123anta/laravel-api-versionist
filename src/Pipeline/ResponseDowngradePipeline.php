<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Pipeline;

use Versionist\ApiVersionist\Exceptions\VersionDowngradeException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;

/**
 * Runs the downgrade chain: transforms response data from a newer version back to an older one.
 */
final class ResponseDowngradePipeline
{
    public function __construct(
        private readonly TransformerRegistry $registry,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(array $data, string $from, string $to): array
    {
        $chain = $this->registry->getDowngradeChain($from, $to);

        foreach ($chain as $transformer) {
            try {
                $data = $transformer->downgradeResponse($data);
            } catch (\Throwable $e) {
                throw new VersionDowngradeException(
                    $from,
                    $to,
                    sprintf(
                        'Transformer %s::downgradeResponse() failed: %s',
                        $transformer::class,
                        $e->getMessage()
                    ),
                    0,
                    $e,
                );
            }
        }

        return $data;
    }
}
