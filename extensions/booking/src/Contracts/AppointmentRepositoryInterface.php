<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;

/**
 * Persistence contract for appointments.
 * @api
 */
#[Api(since: '1.0.0')]
interface AppointmentRepositoryInterface
{
    /**
     * Find an appointment by its ID.
     */
    public function findById(string $id): ?Appointment;

    /**
     * Find an appointment by its booking number.
     */
    public function findByBookingNumber(string $bookingNumber): ?Appointment;

    /**
     * Find appointments for a specific date.
     *
     * @return list<Appointment>
     */
    public function findByDate(DateTimeImmutable $date): array;

    /**
     * Find appointments for a specific customer.
     *
     * @return list<Appointment>
     */
    public function findByCustomer(string $customerId): array;

    /**
     * Find upcoming appointments (scheduled after now).
     *
     * @return list<Appointment>
     */
    public function findUpcoming(int $limit = 50): array;

    /**
     * Find appointments by status.
     *
     * @return list<Appointment>
     */
    public function findByStatus(AppointmentStatus $status, int $limit = 50): array;

    /**
     * Find appointments that need a reminder sent.
     *
     * Returns appointments that are confirmed/deposit-paid, have not had
     * a reminder sent, and are scheduled within the given hours from now.
     *
     * @return list<Appointment>
     */
    public function findNeedingReminder(int $hoursBeforeAppointment): array;

    /**
     * Save (insert or update) an appointment.
     */
    public function save(Appointment $appointment): void;

    /**
     * Delete an appointment by its ID.
     */
    public function delete(string $id): void;

    /**
     * Count appointments by status within a date range.
     *
     * @return array<string, int> Status value => count
     */
    public function countByStatus(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
