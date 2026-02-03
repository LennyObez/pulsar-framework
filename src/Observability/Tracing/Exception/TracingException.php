<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing\Exception;

use RuntimeException;

use function sprintf;

/**
 * Exception thrown for tracing system errors.
 */
final class TracingException extends RuntimeException
{
    public static function invalidTraceId(string $value): self
    {
        return new self(sprintf(
            'Invalid trace ID: expected 32 hex characters, got "%s"',
            $value,
        ));
    }

    public static function invalidSpanId(string $value): self
    {
        return new self(sprintf(
            'Invalid span ID: expected 16 hex characters, got "%s"',
            $value,
        ));
    }
}
