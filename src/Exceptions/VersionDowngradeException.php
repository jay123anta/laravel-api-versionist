<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use RuntimeException;

/** Thrown when a response downgrade transformation fails. */
final class VersionDowngradeException extends RuntimeException
{
    public readonly string $fromVersion;
    public readonly string $toVersion;

    public function __construct(
        string $fromVersion,
        string $toVersion,
        string $reason = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->fromVersion = $fromVersion;
        $this->toVersion = $toVersion;

        $message = sprintf(
            'Failed to downgrade response from version "%s" to "%s".',
            $fromVersion,
            $toVersion
        );

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message, $code, $previous);
    }

    public static function between(string $from, string $to, string $reason = ''): static
    {
        return new static($from, $to, $reason);
    }
}
