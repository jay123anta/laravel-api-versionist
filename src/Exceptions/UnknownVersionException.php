<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested API version is not recognized or registered.
 *
 * This typically occurs when a client requests a version string that
 * does not map to any registered {@see VersionTransformerInterface}.
 */
final class UnknownVersionException extends RuntimeException
{
    /**
     * The unrecognized version string.
     *
     * @var string
     */
    public readonly string $version;

    /**
     * List of versions that are currently available.
     *
     * @var array<int, string>
     */
    public readonly array $availableVersions;

    /**
     * Create a new UnknownVersionException instance.
     *
     * @param  string          $version            The unrecognized version string.
     * @param  array<int, string>  $availableVersions  The list of available versions.
     * @param  int             $code               The exception code.
     * @param  \Throwable|null $previous           The previous throwable for chaining.
     */
    public function __construct(
        string $version,
        array $availableVersions = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->version = $version;
        $this->availableVersions = $availableVersions;

        $message = sprintf('Unknown API version "%s".', $version);

        if ($availableVersions !== []) {
            $message .= sprintf(' Available versions: %s.', implode(', ', $availableVersions));
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Named constructor for creating an exception from a version string.
     *
     * @param  string              $version            The unrecognized version string.
     * @param  array<int, string>  $availableVersions  The list of available versions.
     * @return static
     */
    public static function forVersion(string $version, array $availableVersions = []): static
    {
        return new static($version, $availableVersions);
    }
}
