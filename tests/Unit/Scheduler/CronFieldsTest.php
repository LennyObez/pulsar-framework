<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\CronFields;
use Pulsar\Scheduler\Exception\SchedulerException;

#[CoversClass(CronFields::class)]
final class CronFieldsTest extends TestCase
{
    #[Test]
    public function parseValidExpression(): void
    {
        $fields = CronFields::parse('5 14 1 6 3');

        self::assertSame('5', $fields->minute);
        self::assertSame('14', $fields->hour);
        self::assertSame('1', $fields->dayOfMonth);
        self::assertSame('6', $fields->month);
        self::assertSame('3', $fields->dayOfWeek);
    }

    #[Test]
    public function parseWithWildcards(): void
    {
        $fields = CronFields::parse('* * * * *');

        self::assertSame('*', $fields->minute);
        self::assertSame('*', $fields->hour);
        self::assertSame('*', $fields->dayOfMonth);
        self::assertSame('*', $fields->month);
        self::assertSame('*', $fields->dayOfWeek);
    }

    #[Test]
    public function parseWithWrongNumberOfFieldsThrowsSchedulerException(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageIsOrContains('expected 5 fields, got 3');

        $_ = CronFields::parse('* * *');
    }

    #[Test]
    public function parseWithTooManyFieldsThrowsSchedulerException(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageIsOrContains('expected 5 fields, got 6');

        $_ = CronFields::parse('* * * * * *');
    }

    #[Test]
    public function matchesWithWildcard(): void
    {
        $fields = CronFields::parse('* * * * *');

        // Wildcard should match any time
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-06-15 23:59:00')));
    }

    #[Test]
    public function matchesWithSpecificNumber(): void
    {
        $fields = CronFields::parse('30 9 * * *');

        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:31:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 10:30:00')));
    }

    #[Test]
    public function matchesWithRange(): void
    {
        // Day of week 1-5 (Monday through Friday)
        $fields = CronFields::parse('0 9 * * 1-5');

        // Monday 2026-01-05 at 09:00
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:00:00')));
        // Wednesday 2026-01-07 at 09:00
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-07 09:00:00')));
        // Friday 2026-01-09 at 09:00
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-09 09:00:00')));
        // Sunday 2026-01-04 at 09:00 (day of week = 0)
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-04 09:00:00')));
        // Saturday 2026-01-10 at 09:00 (day of week = 6)
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-10 09:00:00')));
    }

    #[Test]
    public function matchesWithStep(): void
    {
        $fields = CronFields::parse('*/5 * * * *');

        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:05:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:10:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:55:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:01:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:13:00')));
    }

    #[Test]
    public function matchesWithCommaSeparatedValues(): void
    {
        $fields = CronFields::parse('1,15,30 * * * *');

        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:01:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:15:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:02:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:29:00')));
    }

    #[Test]
    public function matchesWithCommaSeparatedRange(): void
    {
        // Minute field "1,2-5": the single value 1 plus the range 2..5.
        // Before the fix, intval('2-5') truncated to 2, so minutes 3, 4 and 5
        // were silently never matched.
        $fields = CronFields::parse('1,2-5 * * * *');

        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:01:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:02:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:03:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:04:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:05:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:06:00')));
    }

    #[Test]
    public function matchesWithCommaSeparatedStep(): void
    {
        // Minute field "0,20-40/5": minute 0 plus the stepped range 20..40/5
        // (20, 25, 30, 35, 40). Before the fix, intval('20-40/5') collapsed
        // the stepped-range token to 20.
        $fields = CronFields::parse('0,20-40/5 * * * *');

        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:20:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:25:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:35:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:40:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:22:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:45:00')));
    }

    #[Test]
    public function matchesWithRangeAndStep(): void
    {
        // Minutes 0-30, every 10 minutes: 0, 10, 20, 30
        $fields = CronFields::parse('0-30/10 * * * *');

        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:00:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:10:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:20:00')));
        self::assertTrue($fields->matches(new DateTimeImmutable('2026-01-05 09:30:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:05:00')));
        self::assertFalse($fields->matches(new DateTimeImmutable('2026-01-05 09:40:00')));
    }
}
