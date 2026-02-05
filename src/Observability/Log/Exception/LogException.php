<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for logging errors.
 */
#[Api]
final class LogException extends RuntimeException
{
    /**
     * A sink failed to write a log entry.
     */
    public static function sinkWriteFailed(string $sink, string $reason): self
    {
        return new self(sprintf('Log sink "%s" write failed: %s', $sink, $reason));
    }

    /**
     * An invalid log driver was specified.
     */
    public static function invalidDriver(string $driver): self
    {
        return new self(sprintf('Invalid log driver: "%s"', $driver));
    }
}
