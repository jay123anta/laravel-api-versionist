<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use RuntimeException;

/**
 * Thrown when a response downgrade transformation fails.
 *
 * This can occur when data required for a downgrade is missing, when a
 * structural transformation cannot be applied, or when an incompatible
 * change prevents the response from being expressed in the target version.
 */
final class VersionDowngradeException extends RuntimeException
{
    /**
     * The source version the response was generated in.
     *
     * @var string
     */
    public readonly string $fromVersion;

    /**
     * The target version the response was being downgraded to.
     *
     * @var string
     */
    public readonly string $toVersion;

    /**
     * Create a new VersionDowngradeException instance.
     *
     * @param  string          $fromVersion  The source version.
     * @param  string          $toVersion    The target version.
     * @param  string          $reason       A human-readable reason for the failure.
     * @param  int             $code         The exception code.
     * @param  \Throwable|null $previous     The previous throwable for chaining.
     */
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

    /**
     * Named constructor for a downgrade failure between two versions.
     *
     * @param  string  $from    The source version.
     * @param  string  $to      The target version.
     * @param  string  $reason  A human-readable reason for the failure.
     * @return static
     */
    public static function between(string $from, string $to, string $reason = ''): static
    {
        return new static($from, $to, $reason);
    }
}
