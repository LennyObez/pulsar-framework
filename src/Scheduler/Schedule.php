<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Scheduler\Exception\SchedulerException;

use function sprintf;

/**
 * Schedule definition for a job, backed by a cron expression.
 */
#[Api(since: '1.0.0')]
final readonly class Schedule
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
    #[NoDiscard]
    public static function everyMinute(string $timezone = 'UTC'): self
    {
        return new self('* * * * *', $timezone);
    }

    /**
     * Every five minutes.
     */
    #[NoDiscard]
    public static function everyFiveMinutes(string $timezone = 'UTC'): self
    {
        return new self('*/5 * * * *', $timezone);
    }

    /**
     * Every N minutes, where N divides 60 evenly (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30).
     *
     * Cron step syntax only produces a regular schedule when N divides
     * 60 — otherwise the intervals drift at the hour boundary. The
     * argument is validated against that constraint to catch misuse at
     * call time instead of silently producing an uneven schedule.
     *
     * @throws SchedulerException If $minutes does not divide 60 evenly
     *                            or is outside the inclusive range [1, 30].
     */
    #[NoDiscard]
    public static function everyMinutes(int $minutes, string $timezone = 'UTC'): self
    {
        if ($minutes < 1 || $minutes > 30 || 60 % $minutes !== 0) {
            throw SchedulerException::invalidEveryMinutesInterval($minutes);
        }

        return new self(sprintf('*/%d * * * *', $minutes), $timezone);
    }

    /**
     * Every hour at minute 0.
     */
    #[NoDiscard]
    public static function hourly(string $timezone = 'UTC'): self
    {
        return new self('0 * * * *', $timezone);
    }

    /**
     * Daily at midnight.
     */
    #[NoDiscard]
    public static function daily(string $timezone = 'UTC'): self
    {
        return new self('0 0 * * *', $timezone);
    }

    /**
     * Daily at a specific time (HH:MM).
     */
    #[NoDiscard]
    public static function dailyAt(string $time, string $timezone = 'UTC'): self
    {
        [$hour, $minute] = explode(':', $time);

        return new self(sprintf('%d %d * * *', (int) $minute, (int) $hour), $timezone);
    }

    /**
     * Weekly on Sunday at midnight.
     */
    #[NoDiscard]
    public static function weekly(string $timezone = 'UTC'): self
    {
        return new self('0 0 * * 0', $timezone);
    }

    /**
     * Monthly on the 1st at midnight.
     */
    #[NoDiscard]
    public static function monthly(string $timezone = 'UTC'): self
    {
        return new self('0 0 1 * *', $timezone);
    }

    /**
     * Custom cron expression.
     */
    #[NoDiscard]
    public static function cron(string $expression, string $timezone = 'UTC'): self
    {
        return new self($expression, $timezone);
    }
}
