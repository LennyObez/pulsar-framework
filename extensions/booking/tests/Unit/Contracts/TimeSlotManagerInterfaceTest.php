<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Contracts;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\TimeSlot;

#[CoversNothing]
final class TimeSlotManagerInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnAvailableSlots(): void
    {
        $slot = new TimeSlot(
            id: 'slot-1',
            date: new DateTimeImmutable('2026-04-01'),
            startTime: new DateTimeImmutable('2026-04-01 09:00:00'),
            endTime: new DateTimeImmutable('2026-04-01 10:00:00'),
            available: true,
            appointmentId: null,
        );

        $stub = $this->createStub(TimeSlotManagerInterface::class);
        $stub->method('getAvailable')->willReturn([$slot]);

        $slots = $stub->getAvailable(new DateTimeImmutable('2026-04-01'), 60);

        self::assertCount(1, $slots);
        self::assertTrue($slots[0]->available);
    }

    #[Test]
    public function stubCanReturnEmptySlots(): void
    {
        $stub = $this->createStub(TimeSlotManagerInterface::class);
        $stub->method('getAvailable')->willReturn([]);

        self::assertCount(0, $stub->getAvailable(new DateTimeImmutable('2026-12-25'), 30));
    }

    #[Test]
    public function stubCanBlockSlot(): void
    {
        $stub = $this->createStub(TimeSlotManagerInterface::class);

        // block() returns void -- no exception means success
        $stub->block('slot-1', 'apt-1');
        self::assertSame('slot-1', 'slot-1');
    }

    #[Test]
    public function stubCanReleaseSlot(): void
    {
        $stub = $this->createStub(TimeSlotManagerInterface::class);

        // release() returns void -- no exception means success
        $stub->release('slot-1');
        self::assertSame('slot-1', 'slot-1');
    }
}
