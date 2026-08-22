<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\TimeSlot;
use Pulsar\Extension\Booking\Exception\BookingException;

/**
 * Manages time slot availability using the database.
 */
#[Internal]
final readonly class TimeSlotManager implements TimeSlotManagerInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getAvailable(DateTimeImmutable $date, int $durationMinutes): array
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_time_slots WHERE date = :date AND available = 1 ORDER BY start_time ASC',
            ['date' => $date->format('Y-m-d')],
        );

        $slots = [];

        foreach ($result->rows as $row) {
            $slot = $this->hydrateSlot($row);

            if ($slot->durationMinutes() >= $durationMinutes) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    #[Override]
    public function block(string $slotId, string $appointmentId): void
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_time_slots WHERE id = :id',
            ['id' => $slotId],
        );

        $row = $result->first();

        if ($row === null || !$row->getBool('available')) {
            throw BookingException::slotNotAvailable($slotId);
        }

        $this->connection->execute(
            'UPDATE booking_time_slots SET available = 0, appointment_id = :appointment_id WHERE id = :id',
            ['id' => $slotId, 'appointment_id' => $appointmentId],
        );
    }

    #[Override]
    public function release(string $slotId): void
    {
        $this->connection->execute(
            'UPDATE booking_time_slots SET available = 1, appointment_id = NULL WHERE id = :id',
            ['id' => $slotId],
        );
    }

    private function hydrateSlot(Row $row): TimeSlot
    {
        return new TimeSlot(
            id: $row->getString('id'),
            date: new DateTimeImmutable($row->getString('date')),
            startTime: new DateTimeImmutable($row->getString('start_time')),
            endTime: new DateTimeImmutable($row->getString('end_time')),
            available: $row->getBool('available'),
            appointmentId: $row->getNullableString('appointment_id'),
        );
    }
}
