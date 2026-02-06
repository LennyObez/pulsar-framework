<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for supervisor-related errors.
 *
 * Provides static factory methods for specific supervisor error scenarios.
 */
#[Api]
final class SupervisorException extends RuntimeException
{
    /**
     * A preflight check failed, preventing the supervisor from starting.
     */
    #[NoDiscard]
    public static function preflightFailed(string $checkName, string $reason): self
    {
        return new self(sprintf(
            'Preflight check "%s" failed: %s',
            $checkName,
            $reason,
        ));
    }

    /**
     * A runtime invariant was violated while the supervisor was active.
     */
    #[NoDiscard]
    public static function invariantViolation(string $checkName, string $reason): self
    {
        return new self(sprintf(
            'Invariant violation in "%s": %s',
            $checkName,
            $reason,
        ));
    }

    /**
     * A stuck job recovery operation failed.
     */
    #[NoDiscard]
    public static function recoveryFailed(string $jobId, string $reason): self
    {
        return new self(sprintf(
            'Recovery failed for job "%s": %s',
            $jobId,
            $reason,
        ));
    }
}
