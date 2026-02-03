<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics\Exception;

use RuntimeException;

use function sprintf;

/**
 * Exception thrown for metrics system errors.
 */
final class MetricsException extends RuntimeException
{
    public static function negativeIncrement(float $value): self
    {
        return new self(sprintf(
            'Counter increment must be non-negative, got %s',
            (string) $value,
        ));
    }

    public static function typeMismatch(string $name, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Metric "%s" already registered as %s, cannot re-register as %s',
            $name,
            $expected,
            $actual,
        ));
    }
}
