<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use InvalidArgumentException;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;

/** Thrown when a class is not a valid version transformer. */
final class InvalidTransformerException extends InvalidArgumentException
{
    public readonly string $transformerClass;

    public function __construct(
        string $transformerClass,
        string $reason = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->transformerClass = $transformerClass;

        $message = sprintf('Invalid transformer class "%s".', $transformerClass);

        if ($reason !== '') {
            $message .= ' ' . $reason;
        } else {
            $message .= sprintf(
                ' Class must implement %s.',
                VersionTransformerInterface::class
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public static function forClass(string $class): static
    {
        return new static(
            $class,
            sprintf('Class must implement %s.', VersionTransformerInterface::class)
        );
    }

    public static function classNotFound(string $class): static
    {
        return new static($class, 'Class does not exist.');
    }
}
