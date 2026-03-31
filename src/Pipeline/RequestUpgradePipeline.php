<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Pipeline;

use Versionist\ApiVersionist\Exceptions\VersionUpgradeException;
use Versionist\ApiVersionist\Registry\TransformerRegistry;

/**
 * Runs the upgrade chain: transforms request data from an older version to a newer one.
 */
final class RequestUpgradePipeline
{
    public function __construct(
        private readonly TransformerRegistry $registry,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(array $data, string $from, string $to): array
    {
        $chain = $this->registry->getUpgradeChain($from, $to);

        foreach ($chain as $transformer) {
            try {
                $data = $transformer->upgradeRequest($data);
            } catch (\Throwable $e) {
                throw new VersionUpgradeException(
                    $from,
                    $to,
                    sprintf(
                        'Transformer %s::upgradeRequest() failed: %s',
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
