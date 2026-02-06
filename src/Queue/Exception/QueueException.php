<?php

declare(strict_types=1);

namespace Pulsar\Queue\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for queue system errors.
 */
#[Api]
final class QueueException extends RuntimeException
{
    /**
     * The requested driver is not configured or unavailable.
     */
    public static function driverNotConfigured(string $driver): self
    {
        return new self(sprintf('Queue driver "%s" is not configured', $driver));
    }

    /**
     * A job with the given identifier could not be found.
     */
    public static function jobNotFound(string $id): self
    {
        return new self(sprintf('Queue job not found: "%s"', $id));
    }

    /**
     * A job failed during execution.
     */
    public static function jobFailed(string $id, string $reason): self
    {
        return new self(sprintf('Queue job "%s" failed: %s', $id, $reason));
    }

    /**
     * A job exceeded its maximum allowed attempts.
     */
    public static function maxAttemptsExceeded(string $id, int $attempts): self
    {
        return new self(sprintf(
            'Queue job "%s" exceeded maximum attempts (%d)',
            $id,
            $attempts,
        ));
    }

    /**
     * A job class could not be serialized or deserialized.
     */
    public static function serializationFailed(string $jobClass): self
    {
        return new self(sprintf('Failed to serialize/deserialize job class "%s"', $jobClass));
    }
}
