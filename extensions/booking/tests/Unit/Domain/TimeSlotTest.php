<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\TimeSlot;

#[CoversClass(TimeSlot::class)]
final class TimeSlotTest extends TestCase
{
    private function makeSlot(bool $available = true, ?string $appointmentId = null): TimeSlot
    {
        return new TimeSlot(
            id: 'slot-001',
            date: new DateTimeImmutable('2026-04-15'),
            startTime: new DateTimeImmutable('2026-04-15 10:00:00'),
            endTime: new DateTimeImmutable('2026-04-15 11:00:00'),
            available: $available,
            appointmentId: $appointmentId,
        );
    }

    public function testBlock(): void
    {
        $slot = $this->makeSlot();

        $blocked = $slot->block('apt-001');

        self::assertFalse($blocked->available);
        self::assertSame('apt-001', $blocked->appointmentId);
        self::assertTrue($slot->available);
    }

    public function testRelease(): void
    {
        $slot = $this->makeSlot(available: false, appointmentId: 'apt-001');

        $released = $slot->release();

        self::assertTrue($released->available);
        self::assertNull($released->appointmentId);
    }

    public function testDurationMinutes(): void
    {
        $slot = $this->makeSlot();

        self::assertSame(60, $slot->durationMinutes());
    }

    public function testDurationMinutesForShortSlot(): void
    {
        $slot = new TimeSlot(
            id: 'slot-short',
            date: new DateTimeImmutable('2026-04-15'),
            startTime: new DateTimeImmutable('2026-04-15 10:00:00'),
            endTime: new DateTimeImmutable('2026-04-15 10:30:00'),
            available: true,
            appointmentId: null,
        );

        self::assertSame(30, $slot->durationMinutes());
    }

    /**
     * @return iterable<string, array{DateTimeImmutable, DateTimeImmutable, bool}>
     */
    public static function overlapProvider(): iterable
    {
        yield 'overlaps at start' => [
            new DateTimeImmutable('2026-04-15 09:30:00'),
            new DateTimeImmutable('2026-04-15 10:30:00'),
            true,
        ];
        yield 'overlaps at end' => [
            new DateTimeImmutable('2026-04-15 10:30:00'),
            new DateTimeImmutable('2026-04-15 11:30:00'),
            true,
        ];
        yield 'fully contained' => [
            new DateTimeImmutable('2026-04-15 10:15:00'),
            new DateTimeImmutable('2026-04-15 10:45:00'),
            true,
        ];
        yield 'fully contains slot' => [
            new DateTimeImmutable('2026-04-15 09:00:00'),
            new DateTimeImmutable('2026-04-15 12:00:00'),
            true,
        ];
        yield 'before slot' => [
            new DateTimeImmutable('2026-04-15 08:00:00'),
            new DateTimeImmutable('2026-04-15 10:00:00'),
            false,
        ];
        yield 'after slot' => [
            new DateTimeImmutable('2026-04-15 11:00:00'),
            new DateTimeImmutable('2026-04-15 12:00:00'),
            false,
        ];
    }

    #[DataProvider('overlapProvider')]
    public function testOverlaps(DateTimeImmutable $start, DateTimeImmutable $end, bool $expected): void
    {
        $slot = $this->makeSlot();

        self::assertSame($expected, $slot->overlaps($start, $end));
    }
}
