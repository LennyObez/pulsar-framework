<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Exception\BookingException;

/**
 * Core booking operations.
 */
#[Api(since: '1.0.0')]
interface BookingServiceInterface
{
    /**
     * Request a new appointment.
     *
     * @throws BookingException If the slot is unavailable or booking constraints are violated
     */
    public function requestAppointment(
        string $serviceId,
        string $customerId,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        DateTimeImmutable $scheduledAt,
        string $notes = '',
    ): Appointment;

    /**
     * Confirm a requested appointment.
     *
     * @throws BookingException If the appointment cannot be confirmed
     */
    public function confirm(string $appointmentId): Appointment;

    /**
     * Cancel an appointment.
     *
     * @throws BookingException If the appointment cannot be cancelled
     */
    public function cancel(string $appointmentId): Appointment;

    /**
     * Reschedule an appointment to a new date/time.
     *
     * @throws BookingException If the appointment cannot be rescheduled
     */
    public function reschedule(string $appointmentId, DateTimeImmutable $newScheduledAt): Appointment;

    /**
     * Mark an appointment as completed.
     *
     * @throws BookingException If the appointment cannot be marked completed
     */
    public function markCompleted(string $appointmentId): Appointment;

    /**
     * Mark an appointment as no-show.
     *
     * @throws BookingException If the appointment cannot be marked as no-show
     */
    public function markNoShow(string $appointmentId): Appointment;
}
