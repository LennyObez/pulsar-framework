<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for scheduler errors.
 * @api
 */
#[Api(since: '1.0.0')]
final class SchedulerException extends RuntimeException
{
    /**
     * Requested job was not found in the registry.
     */
    #[NoDiscard]
    public static function jobNotFound(string $name): self
    {
        return new self(sprintf('Scheduled job not found: "%s"', $name));
    }

    /**
     * Job execution exceeded the maximum allowed time.
     */
    #[NoDiscard]
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
    #[NoDiscard]
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
    #[NoDiscard]
    public static function duplicateJob(string $name): self
    {
        return new self(sprintf('A job named "%s" is already registered', $name));
    }

    /**
     * `Schedule::everyMinutes($n)` was called with an interval that
     * cannot be expressed cleanly in cron step syntax.
     *
     * Valid intervals are divisors of 60 in the range [1..30]:
     * 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30.
     */
    #[NoDiscard]
    public static function invalidEveryMinutesInterval(int $minutes): self
    {
        return new self(sprintf(
            'Schedule::everyMinutes(%d) is not a valid interval. Use one of: 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30.',
            $minutes,
        ));
    }

    /**
     * Overlap prevention was requested without a lock to enforce it with.
     *
     * A job that says `withoutOverlapping()` promises its operator that two
     * runs cannot overlap. Nothing in a single PHP process can keep that
     * promise: scheduler ticks arrive as separate `cron` invocations, so an
     * in-process flag is gone before the next tick can read it. Rather than
     * accept the request and silently guarantee nothing, the job refuses to be
     * built without a {@see \Pulsar\Cache\Application\Lock\LockInterface} that
     * outlives the process.
     */
    #[NoDiscard]
    public static function overlapPreventionRequiresLock(string $name): self
    {
        return new self(sprintf(
            'Job "%s" requests overlap prevention without a lock. Overlap prevention is enforced by a '
            . 'Pulsar\Cache\Application\Lock\LockInterface shared by every process that runs the '
            . 'scheduler — pass one to ScheduleBuilder::withoutOverlapping() (FilesystemLock on a shared '
            . 'volume, RedisLock or DatabaseLock across hosts). Without it the job cannot detect a run '
            . 'started by another tick or another host, and would report protection it does not have.',
            $name,
        ));
    }

    /**
     * The overlap lock was given a lifetime that expires before it is taken.
     *
     * A non-positive expiry produces a lock that is already stale the instant
     * it is written, so every competing run finds it free — a control that
     * cannot be observed holding is indistinguishable from no control at all.
     */
    #[NoDiscard]
    public static function invalidOverlapExpiry(string $name, int $minutes): self
    {
        return new self(sprintf(
            'Job "%s" requests overlap prevention expiring after %d minute(s). The expiry is the lock\'s '
            . 'lifetime and must be at least 1 minute, and longer than the job\'s worst-case run time: a '
            . 'lock that expires while the job is still running lets the next tick start a second copy.',
            $name,
            $minutes,
        ));
    }

    /**
     * Create exception for a scheduled class that is neither a job nor invokable.
     */
    #[NoDiscard]
    public static function jobNotRunnable(string $jobClass): self
    {
        return new self(sprintf(
            'Scheduled class "%s" must implement JobInterface or be invokable',
            $jobClass,
        ));
    }
}
