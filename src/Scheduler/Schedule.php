<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Api;
use Pulsar\Scheduler\Exception\SchedulerException;

use function sprintf;

/**
 * Schedule definition for a job, backed by a cron expression.
 */
#[Api]
readonly class Schedule
{
    public function __construct(
        public string $expression,
        public string $timezone = 'UTC',
    ) {}

    /**
     * Check if this schedule is due at the given time.
     *
     * @throws DateInvalidTimeZoneException If the timezone is invalid
     * @throws SchedulerException If the cron expression is invalid
     */
    public function isDue(DateTimeImmutable $now): bool
    {
        $tz = new DateTimeZone($this->timezone);
        $localNow = $now->setTimezone($tz);
        $fields = CronFields::parse($this->expression);

        return $fields->matches($localNow);
    }

    /**
     * Every minute.
     */
    public static function everyMinute(string $timezone = 'UTC'): self
    {
        return new self('* * * * *', $timezone);
    }

    /**
     * Every five minutes.
     */
    public static function everyFiveMinutes(string $timezone = 'UTC'): self
    {
        return new self('*/5 * * * *', $timezone);
    }

    /**
     * Every hour at minute 0.
     */
    public static function hourly(string $timezone = 'UTC'): self
    {
        return new self('0 * * * *', $timezone);
    }

    /**
     * Daily at midnight.
     */
    public static function daily(string $timezone = 'UTC'): self
    {
        return new self('0 0 * * *', $timezone);
    }

    /**
     * Daily at a specific time (HH:MM).
     */
    public static function dailyAt(string $time, string $timezone = 'UTC'): self
    {
        [$hour, $minute] = explode(':', $time);

        return new self(sprintf('%d %d * * *', (int) $minute, (int) $hour), $timezone);
    }

    /**
     * Weekly on Sunday at midnight.
     */
    public static function weekly(string $timezone = 'UTC'): self
    {
        return new self('0 0 * * 0', $timezone);
    }

    /**
     * Monthly on the 1st at midnight.
     */
    public static function monthly(string $timezone = 'UTC'): self
    {
        return new self('0 0 1 * *', $timezone);
    }

    /**
     * Custom cron expression.
     */
    public static function cron(string $expression, string $timezone = 'UTC'): self
    {
        return new self($expression, $timezone);
    }
}
