<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;

final class FixedClockTest extends TestCase
{
    #[Test]
    public function nowReturnsConfiguredTime(): void
    {
        $time = new DateTimeImmutable('2024-06-15 12:00:00');
        $clock = new FixedClock($time);

        self::assertSame($time->getTimestamp(), $clock->now()->getTimestamp());
    }

    #[Test]
    public function advanceMovesClockForward(): void
    {
        $time = new DateTimeImmutable('2024-06-15 12:00:00');
        $clock = new FixedClock($time);

        $clock->advance(60);

        self::assertSame($time->getTimestamp() + 60, $clock->now()->getTimestamp());
    }

    #[Test]
    public function setChangesClockTime(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01'));
        $newTime = new DateTimeImmutable('2025-06-15');

        $clock->set($newTime);

        self::assertSame($newTime->getTimestamp(), $clock->now()->getTimestamp());
    }
}
