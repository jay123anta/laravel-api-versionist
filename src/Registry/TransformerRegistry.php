<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Registry;

use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;
use Versionist\ApiVersionist\Exceptions\InvalidTransformerException;
use Versionist\ApiVersionist\Exceptions\UnknownVersionException;
use Versionist\ApiVersionist\Support\VersionParser;

/**
 * Maintains an ordered registry of API version transformers.
 *
 * Transformers are stored keyed by their normalized version string and kept
 * sorted in ascending version order. The registry exposes methods to build
 * upgrade chains (ASC) and downgrade chains (DESC) between any two known
 * versions — including the implicit baseline version.
 *
 * ## Conceptual model
 *
 * Each transformer defines the *transition into* its version. For example,
 * a V3Transformer knows how to upgrade a request from v2→v3 and downgrade
 * a response from v3→v2. The "baseline" version is the implied version
 * *before* the lowest registered transformer — it has no transformer of
 * its own because no prior version exists to transform from.
 *
 * ```
 * baseline (v1)  ──[V2Transformer]──▶  v2  ──[V3Transformer]──▶  v3
 * ```
 *
 * Given transformers for v2 and v3:
 *   - Upgrade v1→v3: apply V2 then V3 (ascending)
 *   - Downgrade v3→v1: apply V3 then V2 (descending)
 */
final class TransformerRegistry
{
    /**
     * Registered transformers keyed by normalized version string.
     *
     * Always kept sorted in ascending version order after any mutation.
     *
     * @var array<string, VersionTransformerInterface>
     */
    private array $transformers = [];

    /**
     * Register a single transformer instance.
     *
     * The transformer's version() is normalized via VersionParser. If a
     * transformer for the same version is already registered, it is
     * silently replaced.
     *
     * @param  VersionTransformerInterface  $transformer  The transformer to register.
     * @return static                                     Fluent return for chaining.
     *
     * @throws InvalidTransformerException If the transformer's version() returns an invalid string.
     */
    public function register(VersionTransformerInterface $transformer): static
    {
        // Normalize the version string; VersionParser::parse() throws
        // InvalidArgumentException on bad input — we wrap it in our
        // domain-specific exception for consistency.
        try {
            $version = VersionParser::parse($transformer->version());
        } catch (\InvalidArgumentException $e) {
            throw new InvalidTransformerException(
                $transformer::class,
                sprintf('Transformer returned an invalid version string: "%s".', $transformer->version()),
            );
        }

        $this->transformers[$version] = $transformer;

        // Re-sort by version after every insert so chain-building can
        // rely on iteration order matching ascending version order.
        uksort($this->transformers, static fn (string $a, string $b): int => VersionParser::compare($a, $b));

        return $this;
    }

    /**
     * Register multiple transformer instances at once.
     *
     * @param  iterable<VersionTransformerInterface>  $transformers  The transformers to register.
     * @return static                                                Fluent return for chaining.
     */
    public function registerMany(iterable $transformers): static
    {
        foreach ($transformers as $transformer) {
            $this->register($transformer);
        }

        return $this;
    }

    /**
     * Build the upgrade chain (ascending order) needed to walk from $from up to $to.
     *
     * ## Algorithm
     *
     * 1. Normalize both version strings.
     * 2. If $from >= $to, return [] (same version or already newer — no-op).
     * 3. Filter the sorted transformer list to those whose version V satisfies:
     *        $from < V <= $to
     *    This selects every transformer that represents a transition *into*
     *    a version between (exclusive) the source and (inclusive) the target.
     * 4. Return the filtered list in its natural ascending order.
     *
     * Both the baseline version and all registered versions are valid inputs.
     * The baseline itself has no transformer, so it is never included in the
     * returned chain.
     *
     * @param  string  $from  The source version (where the client is).
     * @param  string  $to    The target version (where the server is).
     * @return array<int, VersionTransformerInterface>  Transformers in ascending version order.
     *
     * @throws UnknownVersionException If either version is not known to the registry.
     */
    public function getUpgradeChain(string $from, string $to): array
    {
        $from = VersionParser::parse($from);
        $to   = VersionParser::parse($to);

        $this->assertVersionKnown($from);
        $this->assertVersionKnown($to);

        // Same version or $from is already newer — nothing to do.
        if (VersionParser::compare($from, $to) >= 0) {
            return [];
        }

        // Walk the sorted transformer map and collect every transformer
        // whose version sits strictly above $from and at-or-below $to.
        $chain = [];

        foreach ($this->transformers as $version => $transformer) {
            // Skip transformers at or below the source version — they
            // represent transitions the client has already passed through.
            if (VersionParser::compare($version, $from) <= 0) {
                continue;
            }

            // Stop once we pass the target — everything beyond is irrelevant.
            if (VersionParser::compare($version, $to) > 0) {
                break;
            }

            $chain[] = $transformer;
        }

        return $chain;
    }

    /**
     * Build the downgrade chain (descending order) needed to walk from $from down to $to.
     *
     * ## Algorithm
     *
     * 1. Normalize both version strings.
     * 2. If $from <= $to, return [] (same version or already older — no-op).
     * 3. Build the upgrade chain in the *reverse* direction ($to → $from)
     *    and reverse it. Each transformer in the returned list will have its
     *    downgradeResponse() called to step the response one version down.
     *
     * The result is the same set of transformers as the upgrade path, but in
     * descending order so the caller can walk "downhill" version by version.
     *
     * @param  string  $from  The source version (where the server responded).
     * @param  string  $to    The target version (what the client expects).
     * @return array<int, VersionTransformerInterface>  Transformers in descending version order.
     *
     * @throws UnknownVersionException If either version is not known to the registry.
     */
    public function getDowngradeChain(string $from, string $to): array
    {
        $from = VersionParser::parse($from);
        $to   = VersionParser::parse($to);

        $this->assertVersionKnown($from);
        $this->assertVersionKnown($to);

        // Same version or $from is already older — nothing to do.
        if (VersionParser::compare($from, $to) <= 0) {
            return [];
        }

        // The downgrade chain is the upgrade chain reversed.
        // Upgrade chain gives us [V(to+1), ..., V(from)] in ASC order.
        // Reversing gives [V(from), ..., V(to+1)] in DESC order — exactly
        // the sequence needed to downgrade step-by-step.
        return array_reverse($this->getUpgradeChain($to, $from));
    }

    /**
     * Return all normalized version strings known to the registry.
     *
     * Includes the implicit baseline version as the first element and all
     * registered transformer versions in ascending order.
     *
     * @return array<int, string>  Normalized version strings in ascending order.
     */
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

    /**
     * Check whether a version is known to the registry.
     *
     * A version is "known" if it matches the baseline version or any
     * registered transformer's version.
     *
     * @param  string  $version  The version string to check.
     * @return bool              True if the version is recognized.
     */
    public function isKnownVersion(string $version): bool
    {
        try {
            $normalized = VersionParser::parse($version);
        } catch (\InvalidArgumentException) {
            return false;
        }

        // Check against baseline.
        if ($this->transformers !== [] && $normalized === $this->baselineVersion()) {
            return true;
        }

        return isset($this->transformers[$normalized]);
    }

    /**
     * Return the latest (highest) registered version string.
     *
     * Because transformers are sorted ascending, this is the last key.
     *
     * @return string  The normalized latest version string.
     *
     * @throws \RuntimeException If the registry is empty.
     */
    public function latestVersion(): string
    {
        if ($this->transformers === []) {
            throw new \RuntimeException('Cannot determine latest version: the registry is empty.');
        }

        // array_key_last returns the last key from the sorted map.
        return array_key_last($this->transformers);
    }

    /**
     * Return the implicit baseline version.
     *
     * The baseline is derived as one major version below the lowest
     * registered transformer. For example, if the lowest transformer
     * handles "v2", the baseline is "v1".
     *
     * This represents the version that existed *before* any transformations
     * were defined — the original API contract.
     *
     * @return string  The normalized baseline version string.
     *
     * @throws \RuntimeException If the registry is empty.
     */
    public function baselineVersion(): string
    {
        if ($this->transformers === []) {
            throw new \RuntimeException('Cannot determine baseline version: the registry is empty.');
        }

        // The lowest registered version is the first key after sorting.
        $lowestVersion = array_key_first($this->transformers);
        $majorNumber   = VersionParser::extractNumber($lowestVersion);

        // Baseline is one major version below the lowest transformer.
        // Guard against underflow — if lowest is v1, baseline becomes v0
        // which is still valid (represents the original un-versioned API).
        return VersionParser::parse((string) max(0, $majorNumber - 1));
    }

    /**
     * Retrieve a specific transformer by its version string.
     *
     * @param  string  $version  The version string to look up.
     * @return VersionTransformerInterface  The matching transformer instance.
     *
     * @throws UnknownVersionException If no transformer is registered for the given version.
     */
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

    /**
     * Return all registered transformers keyed by normalized version string.
     *
     * The returned array is sorted in ascending version order.
     *
     * @return array<string, VersionTransformerInterface>
     */
    public function all(): array
    {
        return $this->transformers;
    }

    /**
     * Assert that a normalized version string is known to the registry.
     *
     * A version is known if it is the baseline or has a registered transformer.
     *
     * @param  string  $version  The already-normalized version string.
     * @return void
     *
     * @throws UnknownVersionException If the version is not recognized.
     */
    private function assertVersionKnown(string $version): void
    {
        if (! $this->isKnownVersion($version)) {
            throw UnknownVersionException::forVersion($version, $this->getVersions());
        }
    }
}
