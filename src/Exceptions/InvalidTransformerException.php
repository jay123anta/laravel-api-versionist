<?php

declare(strict_types=1);

namespace Versionist\ApiVersionist\Exceptions;

use InvalidArgumentException;
use Versionist\ApiVersionist\Contracts\VersionTransformerInterface;

/**
 * Thrown when a class does not implement the required transformer interface
 * or is otherwise invalid for use as a version transformer.
 */
final class InvalidTransformerException extends InvalidArgumentException
{
    /**
     * The fully-qualified class name of the invalid transformer.
     *
     * @var string
     */
    public readonly string $transformerClass;

    /**
     * Create a new InvalidTransformerException instance.
     *
     * @param  string          $transformerClass  The FQCN of the invalid transformer.
     * @param  string          $reason            A human-readable reason why the class is invalid.
     * @param  int             $code              The exception code.
     * @param  \Throwable|null $previous          The previous throwable for chaining.
     */
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

    /**
     * Named constructor for a class that does not implement the transformer interface.
     *
     * @param  string  $class  The FQCN that failed validation.
     * @return static
     */
    public static function forClass(string $class): static
    {
        return new static(
            $class,
            sprintf('Class must implement %s.', VersionTransformerInterface::class)
        );
    }

    /**
     * Named constructor for a class that does not exist.
     *
     * @param  string  $class  The FQCN that could not be found.
     * @return static
     */
    public static function classNotFound(string $class): static
    {
        return new static($class, 'Class does not exist.');
    }
}
