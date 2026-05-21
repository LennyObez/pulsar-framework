<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
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

        /** @var array{id: string, date: string, start_time: string, end_time: string, available: int|string, appointment_id: string|null} $row */
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

        /** @var list<array{id: string, date: string, start_time: string, end_time: string, available: int|string, appointment_id: string|null}> $rows */
        $rows = [];
        /** @var array{id: string, date: string, start_time: string, end_time: string, available: int|string, appointment_id: string|null} $row */
        foreach ($result->rows as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            throw BookingException::slotNotAvailable($slotId);
        }

        /** @var array{id: string, date: string, start_time: string, end_time: string, available: int|string, appointment_id: string|null} $row */
        $row = $rows[0];

        if (!(bool) $row['available']) {
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

    /**
     * @param array{id: string, date: string, start_time: string, end_time: string, available: int|string, appointment_id: string|null} $row
     */
    private function hydrateSlot(array $row): TimeSlot
    {
        return new TimeSlot(
            id: $row['id'],
            date: new DateTimeImmutable($row['date']),
            startTime: new DateTimeImmutable($row['start_time']),
            endTime: new DateTimeImmutable($row['end_time']),
            available: (bool) $row['available'],
            appointmentId: $row['appointment_id'],
        );
    }
}
