<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use RuntimeException;

/** Thrown when a requested API version is not recognized. */
final class UnknownVersionException extends RuntimeException
{
    public readonly string $version;

    /** @var array<int, string> */
    public readonly array $availableVersions;

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

    public static function forVersion(string $version, array $availableVersions = []): static
    {
        return new static($version, $availableVersions);
    }
}
