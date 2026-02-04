<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\CronFields;
use Pulsar\Scheduler\Schedule;

#[CoversClass(Schedule::class)]
#[CoversClass(CronFields::class)]
final class ScheduleTest extends TestCase
{
    #[Test]
    public function everyMinuteIsAlwaysDue(): void
    {
        $schedule = Schedule::everyMinute();

        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:01:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 23:59:00')));
    }

    #[Test]
    public function everyFiveMinutesIsDueAtMultiplesOfFive(): void
    {
        $schedule = Schedule::everyFiveMinutes();

        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:05:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:10:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:55:00')));
    }

    #[Test]
    public function everyFiveMinutesIsNotDueAtNonMultiplesOfFive(): void
    {
        $schedule = Schedule::everyFiveMinutes();

        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:01:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:02:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:03:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:04:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:07:00')));
    }

    #[Test]
    public function hourlyIsDueAtMinuteZeroOnly(): void
    {
        $schedule = Schedule::hourly();

        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 14:00:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:01:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:59:00')));
    }

    #[Test]
    public function dailyIsDueAtMidnightOnly(): void
    {
        $schedule = Schedule::daily();

        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 00:00:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 00:01:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 12:00:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 23:59:00')));
    }

    #[Test]
    public function dailyAtIsDueAtSpecifiedTime(): void
    {
        $schedule = Schedule::dailyAt('09:30');

        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:31:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 00:00:00')));
    }

    #[Test]
    public function weeklyIsDueOnSundayAtMidnight(): void
    {
        $schedule = Schedule::weekly();

        // 2026-01-04 is a Sunday
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-04 00:00:00')));
        // Sunday but not midnight
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-04 12:00:00')));
        // Monday at midnight - not Sunday
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 00:00:00')));
        // Saturday at midnight - not Sunday
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-03 00:00:00')));
    }

    #[Test]
    public function monthlyIsDueOnFirstDayAtMidnight(): void
    {
        $schedule = Schedule::monthly();

        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-01 00:00:00')));
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-02-01 00:00:00')));
        // 1st but not midnight
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-01 12:00:00')));
        // Not the 1st
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-02 00:00:00')));
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-15 00:00:00')));
    }

    #[Test]
    public function cronWithCustomExpression(): void
    {
        // Every weekday at 08:00
        $schedule = Schedule::cron('0 8 * * 1-5');

        // Monday 2026-01-05 at 08:00
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 08:00:00')));
        // Friday 2026-01-09 at 08:00
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-09 08:00:00')));
        // Sunday 2026-01-04 at 08:00
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-04 08:00:00')));
        // Monday 2026-01-05 at 09:00
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 09:00:00')));
    }

    #[Test]
    public function isDueRespectsTimezone(): void
    {
        $schedule = Schedule::daily('America/New_York');

        // Midnight in UTC is not midnight in New York (EST = UTC-5)
        self::assertFalse($schedule->isDue(new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('UTC'))));
        // 05:00 UTC = 00:00 EST
        self::assertTrue($schedule->isDue(new DateTimeImmutable('2026-01-05 05:00:00', new DateTimeZone('UTC'))));
    }

    #[Test]
    public function constructorSetsExpressionAndTimezone(): void
    {
        $schedule = new Schedule('*/10 * * * *', 'Europe/London');

        self::assertSame('*/10 * * * *', $schedule->expression);
        self::assertSame('Europe/London', $schedule->timezone);
    }
}
