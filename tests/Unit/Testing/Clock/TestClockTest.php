<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Clock;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Clock\TestClock;

#[CoversClass(TestClock::class)]
final class TestClockTest extends TestCase
{
    #[Test]
    public function frozen_creates_clock_at_current_time(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $clock = TestClock::frozen();
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertGreaterThanOrEqual($before->getTimestamp(), $clock->timestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $clock->timestamp());
    }

    #[Test]
    public function at_creates_clock_at_specific_time(): void
    {
        $clock = TestClock::at('2024-06-15 14:30:00');

        self::assertSame('2024-06-15 14:30:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function from_timestamp_creates_clock_at_unix_time(): void
    {
        $clock = TestClock::fromTimestamp(1700000000);

        self::assertSame(1700000000, $clock->timestamp());
    }

    #[Test]
    public function timestamp_returns_unix_timestamp(): void
    {
        $clock = TestClock::at('2024-01-01 00:00:00');

        self::assertSame($clock->now()->getTimestamp(), $clock->timestamp());
    }

    #[Test]
    public function advance_moves_forward_by_seconds(): void
    {
        $clock = TestClock::at('2024-01-01 12:00:00');

        $clock->advance(seconds: 90);

        self::assertSame('2024-01-01 12:01:30', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function advance_moves_forward_by_minutes(): void
    {
        $clock = TestClock::at('2024-01-01 12:00:00');

        $clock->advance(minutes: 5);

        self::assertSame('2024-01-01 12:05:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function advance_moves_forward_by_hours(): void
    {
        $clock = TestClock::at('2024-01-01 12:00:00');

        $clock->advance(hours: 3);

        self::assertSame('2024-01-01 15:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function advance_moves_forward_by_days(): void
    {
        $clock = TestClock::at('2024-01-01 12:00:00');

        $clock->advance(days: 2);

        self::assertSame('2024-01-03 12:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function advance_combines_multiple_units(): void
    {
        $clock = TestClock::at('2024-01-01 00:00:00');

        $clock->advance(days: 1, hours: 2, minutes: 30, seconds: 15);

        self::assertSame('2024-01-02 02:30:15', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function rewind_moves_backward(): void
    {
        $clock = TestClock::at('2024-01-01 12:00:00');

        $clock->rewind(minutes: 30);

        self::assertSame('2024-01-01 11:30:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function set_to_changes_current_time(): void
    {
        $clock = TestClock::at('2024-01-01 00:00:00');
        $target = new DateTimeImmutable('2025-06-15 18:45:00', new DateTimeZone('UTC'));

        $clock->setTo($target);

        self::assertSame('2025-06-15 18:45:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function time_is_frozen_between_calls(): void
    {
        $clock = TestClock::at('2024-01-01 12:00:00');

        $first = $clock->timestamp();
        $second = $clock->timestamp();

        self::assertSame($first, $second);
    }
}
