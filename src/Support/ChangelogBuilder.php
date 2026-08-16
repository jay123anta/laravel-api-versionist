<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Support;

use Versionist\ApiVersionist\Registry\TransformerRegistry;
use Versionist\ApiVersionist\Version\VersionNegotiator;

/**
 * Builds the version changelog data shared by every api:changelog output
 * format and the changelog HTTP endpoint.
 */
final class ChangelogBuilder
{
    public function __construct(
        private readonly TransformerRegistry $registry,
        private readonly VersionNegotiator $negotiator,
    ) {}

    /**
     * @return array{
     *     baseline: string|null,
     *     latest: string|null,
     *     versions: list<array{
     *         version: string,
     *         is_latest: bool,
     *         is_baseline: bool,
     *         transformer_class?: string,
     *         description: string,
     *         released_at: string|null,
     *         deprecated: bool,
     *         deprecated_at: string|null,
     *         sunset_date: string|null
     *     }>
     * }
     */
    public function build(bool $includeTransformerClass = false): array
    {
        if ($this->registry->all() === []) {
            return [
                'baseline' => null,
                'latest'   => null,
                'versions' => [],
            ];
        }

        $baseline = $this->registry->baselineVersion();
        $latest   = $this->negotiator->latestVersion();

        $versionsData = [];

        foreach ($this->registry->getVersions() as $version) {
            $entry = [
                'version'     => $version,
                'is_latest'   => $version === $latest,
                'is_baseline' => $version === $baseline,
            ];

            if ($version !== $baseline) {
                $transformer = $this->registry->getTransformer($version);

                if ($includeTransformerClass) {
                    $entry['transformer_class'] = $transformer::class;
                }

                $entry['description'] = $transformer->description();
                $entry['released_at'] = $transformer->releasedAt();
            } else {
                $entry['description'] = 'Baseline version (no transformer)';
                $entry['released_at'] = null;
            }

            $entry['deprecated']    = $this->negotiator->isDeprecated($version);
            $entry['deprecated_at'] = $this->negotiator->getDeprecatedAtDate($version);
            $entry['sunset_date']   = $this->negotiator->getSunsetDate($version);

            $versionsData[] = $entry;
        }

        return [
            'baseline' => $baseline,
            'latest'   => $latest,
            'versions' => $versionsData,
        ];
    }
}
