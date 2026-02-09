<?php

declare(strict_types=1);

namespace Pulsar\Queue\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for queue system errors.
 */
#[Api(since: '1.0.0')]
final class QueueException extends RuntimeException
{
    /**
     * The requested driver is not configured or unavailable.
     */
    #[NoDiscard]
    public static function driverNotConfigured(string $driver): self
    {
        return new self(sprintf('Queue driver "%s" is not configured', $driver));
    }

    /**
     * A job with the given identifier could not be found.
     */
    #[NoDiscard]
    public static function jobNotFound(string $id): self
    {
        return new self(sprintf('Queue job not found: "%s"', $id));
    }

    /**
     * A job failed during execution.
     */
    #[NoDiscard]
    public static function jobFailed(string $id, string $reason): self
    {
        return new self(sprintf('Queue job "%s" failed: %s', $id, $reason));
    }

    /**
     * A job exceeded its maximum allowed attempts.
     */
    #[NoDiscard]
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
    #[NoDiscard]
    public static function serializationFailed(string $jobClass): self
    {
        return new self(sprintf('Failed to serialize/deserialize job class "%s"', $jobClass));
    }
}
