<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Exception;

use RuntimeException;

use function sprintf;

/**
 * Exception for scheduler errors.
 */
final class SchedulerException extends RuntimeException
{
    /**
     * Requested job was not found in the registry.
     */
    public static function jobNotFound(string $name): self
    {
        return new self(sprintf('Scheduled job not found: "%s"', $name));
    }

    /**
     * Job execution exceeded the maximum allowed time.
     */
    public static function executionTimeout(string $name, int $timeoutSeconds): self
    {
        return new self(sprintf(
            'Job "%s" exceeded maximum execution time of %d seconds',
            $name,
            $timeoutSeconds,
        ));
    }

    /**
     * Cron expression could not be parsed.
     */
    public static function invalidCronExpression(string $expression, string $reason): self
    {
        return new self(sprintf(
            'Invalid cron expression "%s": %s',
            $expression,
            $reason,
        ));
    }

    /**
     * A job with the same name is already registered.
     */
    public static function duplicateJob(string $name): self
    {
        return new self(sprintf('A job named "%s" is already registered', $name));
    }
}
