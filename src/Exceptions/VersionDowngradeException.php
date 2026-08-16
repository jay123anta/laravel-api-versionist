<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use RuntimeException;

/** Thrown when a response downgrade transformation fails. */
final class VersionDowngradeException extends RuntimeException
{
    public function __construct(
        public readonly string $fromVersion,
        public readonly string $toVersion,
        string $reason = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $message = sprintf(
            'Failed to downgrade response from version "%s" to "%s".',
            $this->fromVersion,
            $this->toVersion
        );

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message, $code, $previous);
    }

    public static function between(string $from, string $to, string $reason = ''): static
    {
        return new self($from, $to, $reason);
    }
}
