<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\SystemClock;

#[CoversClass(FixedClock::class)]
#[CoversClass(SystemClock::class)]
final class ClockTest extends TestCase
{
    #[Test]
    public function fixedClockReturnsInjectedTime(): void
    {
        $time = new DateTimeImmutable('2025-06-01T12:00:00Z');
        $clock = new FixedClock($time);

        self::assertSame($time, $clock->now());
    }

    #[Test]
    public function fixedClockAdvanceMovesForward(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $clock->advance(60);

        self::assertSame('2025-01-01T00:01:00+00:00', $clock->now()->format('c'));
    }

    #[Test]
    public function fixedClockSetReplacesTime(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $newTime = new DateTimeImmutable('2026-06-15T10:30:00Z');
        $clock->set($newTime);

        self::assertSame($newTime, $clock->now());
    }

    #[Test]
    public function systemClockReturnsCurrentTime(): void
    {
        $clock = new SystemClock();
        $before = new DateTimeImmutable();
        $now = $clock->now();
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }
}
