<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Database-backed appointment repository.
 */
#[Internal]
final readonly class DbAppointmentRepository implements AppointmentRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function findById(string $id): ?Appointment
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE id = :id',
            ['id' => $id],
        );

        foreach ($result as $row) {
            /** @var array<string, mixed> $row */
            return $this->hydrate($row);
        }

        return null;
    }

    #[Override]
    public function findByBookingNumber(string $bookingNumber): ?Appointment
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE booking_number = :booking_number',
            ['booking_number' => $bookingNumber],
        );

        foreach ($result as $row) {
            /** @var array<string, mixed> $row */
            return $this->hydrate($row);
        }

        return null;
    }

    #[Override]
    public function findByDate(DateTimeImmutable $date): array
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE DATE(scheduled_at) = :date ORDER BY scheduled_at ASC',
            ['date' => $date->format('Y-m-d')],
        );

        return $this->hydrateAll($result);
    }

    #[Override]
    public function findByCustomer(string $customerId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE customer_id = :customer_id ORDER BY scheduled_at DESC',
            ['customer_id' => $customerId],
        );

        return $this->hydrateAll($result);
    }

    #[Override]
    public function findUpcoming(int $limit = 50): array
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE scheduled_at > :now AND status NOT IN (:cancelled, :completed, :no_show) ORDER BY scheduled_at ASC LIMIT :limit',
            [
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'cancelled' => AppointmentStatus::Cancelled->value,
                'completed' => AppointmentStatus::Completed->value,
                'no_show' => AppointmentStatus::NoShow->value,
                'limit' => $limit,
            ],
        );

        return $this->hydrateAll($result);
    }

    #[Override]
    public function findByStatus(AppointmentStatus $status, int $limit = 50): array
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE status = :status ORDER BY scheduled_at ASC LIMIT :limit',
            ['status' => $status->value, 'limit' => $limit],
        );

        return $this->hydrateAll($result);
    }

    #[Override]
    public function findNeedingReminder(int $hoursBeforeAppointment): array
    {
        $reminderThreshold = new DateTimeImmutable()
            ->modify("+{$hoursBeforeAppointment} hours")
            ->format('Y-m-d H:i:s');

        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

        $result = $this->connection->query(
            'SELECT * FROM booking_appointments WHERE reminder_sent = 0 AND status IN (:confirmed, :deposit_paid) AND scheduled_at > :now AND scheduled_at <= :threshold ORDER BY scheduled_at ASC',
            [
                'confirmed' => AppointmentStatus::Confirmed->value,
                'deposit_paid' => AppointmentStatus::DepositPaid->value,
                'now' => $now,
                'threshold' => $reminderThreshold,
            ],
        );

        return $this->hydrateAll($result);
    }

    #[Override]
    public function save(Appointment $appointment): void
    {
        $existing = $this->findById($appointment->id);

        $params = [
            'id' => $appointment->id,
            'booking_number' => $appointment->bookingNumber,
            'service_id' => $appointment->serviceId,
            'customer_id' => $appointment->customerId,
            'customer_name' => $appointment->customerName,
            'customer_email' => $appointment->customerEmail,
            'customer_phone' => $appointment->customerPhone,
            'status' => $appointment->status->value,
            'scheduled_at' => $appointment->scheduledAt->format('Y-m-d H:i:s'),
            'duration' => $appointment->duration,
            'deposit_amount' => $appointment->depositAmount?->amount,
            'deposit_currency' => $appointment->depositAmount?->currency->value,
            'deposit_paid' => $appointment->depositPaid ? 1 : 0,
            'notes' => $appointment->notes,
            'reminder_sent' => $appointment->reminderSent ? 1 : 0,
            'created_at' => $appointment->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $appointment->updatedAt->format('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            $this->connection->execute(
                'UPDATE booking_appointments SET booking_number = :booking_number, service_id = :service_id, customer_id = :customer_id, customer_name = :customer_name, customer_email = :customer_email, customer_phone = :customer_phone, status = :status, scheduled_at = :scheduled_at, duration = :duration, deposit_amount = :deposit_amount, deposit_currency = :deposit_currency, deposit_paid = :deposit_paid, notes = :notes, reminder_sent = :reminder_sent, updated_at = :updated_at WHERE id = :id',
                $params,
            );
        } else {
            $this->connection->execute(
                'INSERT INTO booking_appointments (id, booking_number, service_id, customer_id, customer_name, customer_email, customer_phone, status, scheduled_at, duration, deposit_amount, deposit_currency, deposit_paid, notes, reminder_sent, created_at, updated_at) VALUES (:id, :booking_number, :service_id, :customer_id, :customer_name, :customer_email, :customer_phone, :status, :scheduled_at, :duration, :deposit_amount, :deposit_currency, :deposit_paid, :notes, :reminder_sent, :created_at, :updated_at)',
                $params,
            );
        }
    }

    #[Override]
    public function delete(string $id): void
    {
        $this->connection->execute(
            'DELETE FROM booking_appointments WHERE id = :id',
            ['id' => $id],
        );
    }

    #[Override]
    public function countByStatus(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $result = $this->connection->query(
            'SELECT status, COUNT(*) as count FROM booking_appointments WHERE scheduled_at BETWEEN :from AND :to GROUP BY status',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        $counts = [];
        foreach ($result as $row) {
            /** @var array{status: string, count: int|string} $row */
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Appointment
    {
        $depositAmount = null;
        if (isset($row['deposit_amount'], $row['deposit_currency']) && $row['deposit_amount'] !== null) {
            $depositAmount = Money::of(
                (int) $row['deposit_amount'],
                Currency::from((string) $row['deposit_currency']),
            );
        }

        return new Appointment(
            id: (string) $row['id'],
            bookingNumber: (string) $row['booking_number'],
            serviceId: (string) $row['service_id'],
            customerId: (string) $row['customer_id'],
            customerName: (string) $row['customer_name'],
            customerEmail: (string) $row['customer_email'],
            customerPhone: (string) $row['customer_phone'],
            status: AppointmentStatus::from((string) $row['status']),
            scheduledAt: new DateTimeImmutable((string) $row['scheduled_at']),
            duration: (int) $row['duration'],
            depositAmount: $depositAmount,
            depositPaid: (bool) $row['deposit_paid'],
            notes: (string) ($row['notes'] ?? ''),
            reminderSent: (bool) $row['reminder_sent'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @param iterable<array<string, mixed>> $result
     * @return list<Appointment>
     */
    private function hydrateAll(iterable $result): array
    {
        $appointments = [];

        foreach ($result as $row) {
            /** @var array<string, mixed> $row */
            $appointments[] = $this->hydrate($row);
        }

        return $appointments;
    }
}
