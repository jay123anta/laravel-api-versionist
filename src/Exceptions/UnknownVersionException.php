<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use RuntimeException;

/** Thrown when a requested API version is not recognized. */
final class UnknownVersionException extends RuntimeException
{
    /**
     * @param  array<int, string>  $availableVersions
     */
    public function __construct(
        public readonly string $version,
        public readonly array $availableVersions = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $message = sprintf('Unknown API version "%s".', $this->version);

        if ($this->availableVersions !== []) {
            $message .= sprintf(' Available versions: %s.', implode(', ', $this->availableVersions));
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * @param  array<int, string>  $availableVersions
     */
    public static function forVersion(string $version, array $availableVersions = []): static
    {
        return new self($version, $availableVersions);
    }
}
