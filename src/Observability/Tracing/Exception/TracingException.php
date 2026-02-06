<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for tracing system errors.
 */
#[Api]
final class TracingException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidTraceId(string $value): self
    {
        return new self(sprintf(
            'Invalid trace ID: expected 32 hex characters, got "%s"',
            $value,
        ));
    }

    #[NoDiscard]
    public static function invalidSpanId(string $value): self
    {
        return new self(sprintf(
            'Invalid span ID: expected 16 hex characters, got "%s"',
            $value,
        ));
    }
}
