<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Registry;

use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;
use Versionist\ApiVersionist\Exceptions\InvalidTransformerException;
use Versionist\ApiVersionist\Exceptions\UnknownVersionException;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Stores transformers keyed by version, sorted ascending. Builds upgrade/downgrade
 * chains between any two known versions including the implicit baseline.
 */
final class TransformerRegistry
{
    /** @var array<string, VersionTransformerInterface> */
    private array $transformers = [];

    public function register(VersionTransformerInterface $transformer): static
    {
        try {
            $version = VersionParser::parse($transformer->version());
        } catch (\InvalidArgumentException $e) {
            throw new InvalidTransformerException(
                $transformer::class,
                sprintf('Transformer returned an invalid version string: "%s".', $transformer->version()),
            );
        }

        $this->transformers[$version] = $transformer;
        uksort($this->transformers, static fn (string $a, string $b): int => VersionParser::compare($a, $b));

        return $this;
    }

    public function registerMany(iterable $transformers): static
    {
        foreach ($transformers as $transformer) {
            $this->register($transformer);
        }

        return $this;
    }

    /** Returns transformers where $from < version <= $to, in ascending order. */
    public function getUpgradeChain(string $from, string $to): array
    {
        $from = VersionParser::parse($from);
        $to   = VersionParser::parse($to);

        $this->assertVersionKnown($from);
        $this->assertVersionKnown($to);

        if (VersionParser::compare($from, $to) >= 0) {
            return [];
        }

        $chain = [];

        foreach ($this->transformers as $version => $transformer) {
            if (VersionParser::compare($version, $from) <= 0) {
                continue;
            }

            if (VersionParser::compare($version, $to) > 0) {
                break;
            }

            $chain[] = $transformer;
        }

        return $chain;
    }

    /** Same as getUpgradeChain but reversed — descending order for downgrade. */
    public function getDowngradeChain(string $from, string $to): array
    {
        $from = VersionParser::parse($from);
        $to   = VersionParser::parse($to);

        $this->assertVersionKnown($from);
        $this->assertVersionKnown($to);

        if (VersionParser::compare($from, $to) <= 0) {
            return [];
        }

        return array_reverse($this->getUpgradeChain($to, $from));
    }

    /** @return array<int, string> Baseline + all registered versions, ascending. */
    public function getVersions(): array
    {
        if ($this->transformers === []) {
            return [];
        }

        return [
            $this->baselineVersion(),
            ...array_keys($this->transformers),
        ];
    }

    public function isKnownVersion(string $version): bool
    {
        try {
            $normalized = VersionParser::parse($version);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if ($this->transformers !== [] && $normalized === $this->baselineVersion()) {
            return true;
        }

        return isset($this->transformers[$normalized]);
    }

    public function latestVersion(): string
    {
        if ($this->transformers === []) {
            throw new \RuntimeException('Cannot determine latest version: the registry is empty.');
        }

        return array_key_last($this->transformers);
    }

    /** Derives the version before the lowest registered transformer. */
    public function baselineVersion(): string
    {
        if ($this->transformers === []) {
            throw new \RuntimeException('Cannot determine baseline version: the registry is empty.');
        }

        $lowestVersion = array_key_first($this->transformers);

        if (VersionParser::isDate($lowestVersion)) {
            $date = new \DateTimeImmutable($lowestVersion);

            return $date->modify('-1 day')->format('Y-m-d');
        }

        $majorNumber = VersionParser::extractNumber($lowestVersion);

        return VersionParser::parse((string) max(0, $majorNumber - 1));
    }

    public function getTransformer(string $version): VersionTransformerInterface
    {
        $normalized = VersionParser::parse($version);

        if (! isset($this->transformers[$normalized])) {
            throw UnknownVersionException::forVersion(
                $normalized,
                array_keys($this->transformers),
            );
        }

        return $this->transformers[$normalized];
    }

    /** @return array<string, VersionTransformerInterface> */
    public function all(): array
    {
        return $this->transformers;
    }

    private function assertVersionKnown(string $version): void
    {
        if (! $this->isKnownVersion($version)) {
            throw UnknownVersionException::forVersion($version, $this->getVersions());
        }
    }
}
