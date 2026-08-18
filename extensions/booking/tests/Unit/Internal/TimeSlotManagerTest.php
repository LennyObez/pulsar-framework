<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Internal\TimeSlotManager;

#[CoversClass(TimeSlotManager::class)]
final class TimeSlotManagerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private TimeSlotManager $manager;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->manager = new TimeSlotManager($this->connection);
    }

    public function testGetAvailableReturnsMatchingSlots(): void
    {
        $rows = [
            [
                'id' => 'slot-001',
                'date' => '2026-04-15',
                'start_time' => '2026-04-15 10:00:00',
                'end_time' => '2026-04-15 11:00:00',
                'available' => 1,
                'appointment_id' => null,
            ],
            [
                'id' => 'slot-002',
                'date' => '2026-04-15',
                'start_time' => '2026-04-15 11:00:00',
                'end_time' => '2026-04-15 11:30:00',
                'available' => 1,
                'appointment_id' => null,
            ],
        ];

        $result = Result::fromArrays($rows);
        $this->connection->method('query')->willReturn($result);

        $slots = $this->manager->getAvailable(new DateTimeImmutable('2026-04-15'), 60);

        self::assertCount(1, $slots);
        self::assertSame('slot-001', $slots[0]->id);
    }

    public function testBlockThrowsWhenSlotNotAvailable(): void
    {
        $rows = [
            [
                'id' => 'slot-001',
                'date' => '2026-04-15',
                'start_time' => '2026-04-15 10:00:00',
                'end_time' => '2026-04-15 11:00:00',
                'available' => 0,
                'appointment_id' => 'apt-existing',
            ],
        ];

        $result = Result::fromArrays($rows);
        $this->connection->method('query')->willReturn($result);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('not available');

        $this->manager->block('slot-001', 'apt-new');
    }

    public function testBlockThrowsWhenSlotNotFound(): void
    {
        $result = Result::fromArrays([]);
        $this->connection->method('query')->willReturn($result);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('not available');

        $this->manager->block('nonexistent', 'apt-001');
    }
}
