<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Internal;

use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Extension\Booking\Domain\Service;
use Pulsar\Extension\Booking\Exception\BookingException;

use function bin2hex;
use function random_bytes;

/**
 * Core booking service implementation.
 *
 * Handles appointment lifecycle: creation, confirmation, cancellation,
 * rescheduling, and completion. Enforces business rules from BookingConfig.
 */
#[Internal]
final readonly class BookingService implements BookingServiceInterface
{
    public function __construct(
        private AppointmentRepositoryInterface $repository,
        private TimeSlotManagerInterface $timeSlotManager,
        private BookingNumberGenerator $numberGenerator,
        private BookingConfig $config,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function requestAppointment(
        string $serviceId,
        string $customerId,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        DateTimeImmutable $scheduledAt,
        string $notes = '',
    ): Appointment {
        $this->validateBookingWindow($scheduledAt);

        $now = new DateTimeImmutable();
        $depositAmount = null;

        if ($this->config->depositRequired) {
            // Deposit calculation happens at controller level via the Service entity
            // Here we just record the config flag
        }

        $appointment = new Appointment(
            id: bin2hex(random_bytes(16)),
            bookingNumber: $this->numberGenerator->generate(),
            serviceId: $serviceId,
            customerId: $customerId,
            customerName: $customerName,
            customerEmail: $customerEmail,
            customerPhone: $customerPhone,
            status: AppointmentStatus::Requested,
            scheduledAt: $scheduledAt,
            duration: 0, // Set by controller based on service duration
            depositAmount: $depositAmount,
            depositPaid: false,
            notes: $notes,
            reminderSent: false,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->repository->save($appointment);

        $this->logger->info('Appointment requested', [
            'booking_number' => $appointment->bookingNumber,
            'customer' => $customerEmail,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
        ]);

        return $appointment;
    }

    /**
     * Request an appointment with full service details pre-populated.
     *
     * @throws BookingException If constraints are violated
     */
    public function requestWithService(
        Service $service,
        string $customerId,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        DateTimeImmutable $scheduledAt,
        string $notes = '',
    ): Appointment {
        $this->validateBookingWindow($scheduledAt);

        if (!$service->active) {
            throw BookingException::serviceNotFound($service->id);
        }

        $now = new DateTimeImmutable();
        $depositAmount = $this->config->depositRequired ? $service->depositAmount() : null;

        $appointment = new Appointment(
            id: bin2hex(random_bytes(16)),
            bookingNumber: $this->numberGenerator->generate(),
            serviceId: $service->id,
            customerId: $customerId,
            customerName: $customerName,
            customerEmail: $customerEmail,
            customerPhone: $customerPhone,
            status: AppointmentStatus::Requested,
            scheduledAt: $scheduledAt,
            duration: $service->duration,
            depositAmount: $depositAmount,
            depositPaid: false,
            notes: $notes,
            reminderSent: false,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->repository->save($appointment);

        $this->logger->info('Appointment requested', [
            'booking_number' => $appointment->bookingNumber,
            'service' => $service->name,
            'customer' => $customerEmail,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
        ]);

        return $appointment;
    }

    #[Override]
    public function confirm(string $appointmentId): Appointment
    {
        $appointment = $this->findOrFail($appointmentId);
        $confirmed = $appointment->transitionTo(AppointmentStatus::Confirmed);

        $this->repository->save($confirmed);

        $this->logger->info('Appointment confirmed', [
            'booking_number' => $confirmed->bookingNumber,
        ]);

        return $confirmed;
    }

    #[Override]
    public function cancel(string $appointmentId): Appointment
    {
        $appointment = $this->findOrFail($appointmentId);

        $hoursUntilAppointment = ($appointment->scheduledAt->getTimestamp() - new DateTimeImmutable()->getTimestamp()) / 3600;

        if ($hoursUntilAppointment < $this->config->cancellationPolicyHours && $appointment->status !== AppointmentStatus::Requested) {
            throw BookingException::cancellationTooLate($this->config->cancellationPolicyHours);
        }

        $cancelled = $appointment->transitionTo(AppointmentStatus::Cancelled);
        $this->repository->save($cancelled);

        $this->logger->info('Appointment cancelled', [
            'booking_number' => $cancelled->bookingNumber,
        ]);

        return $cancelled;
    }

    #[Override]
    public function reschedule(string $appointmentId, DateTimeImmutable $newScheduledAt): Appointment
    {
        $this->validateBookingWindow($newScheduledAt);

        $appointment = $this->findOrFail($appointmentId);
        $rescheduled = $appointment->reschedule($newScheduledAt, $appointment->duration);

        $this->repository->save($rescheduled);

        $this->logger->info('Appointment rescheduled', [
            'booking_number' => $rescheduled->bookingNumber,
            'new_scheduled_at' => $newScheduledAt->format('Y-m-d H:i:s'),
        ]);

        return $rescheduled;
    }

    #[Override]
    public function markCompleted(string $appointmentId): Appointment
    {
        $appointment = $this->findOrFail($appointmentId);

        // Must be in progress first
        $inProgress = $appointment->status === AppointmentStatus::InProgress
            ? $appointment
            : $appointment->transitionTo(AppointmentStatus::InProgress);

        $completed = $inProgress->transitionTo(AppointmentStatus::Completed);
        $this->repository->save($completed);

        $this->logger->info('Appointment completed', [
            'booking_number' => $completed->bookingNumber,
        ]);

        return $completed;
    }

    #[Override]
    public function markNoShow(string $appointmentId): Appointment
    {
        $appointment = $this->findOrFail($appointmentId);

        // Must be in progress or reminded
        if ($appointment->status !== AppointmentStatus::InProgress
            && $appointment->status !== AppointmentStatus::Reminded
        ) {
            $appointment = $appointment->transitionTo(AppointmentStatus::InProgress);
        }

        $noShow = $appointment->transitionTo(AppointmentStatus::NoShow);
        $this->repository->save($noShow);

        $this->logger->info('Appointment marked as no-show', [
            'booking_number' => $noShow->bookingNumber,
        ]);

        return $noShow;
    }

    private function findOrFail(string $appointmentId): Appointment
    {
        $appointment = $this->repository->findById($appointmentId);

        if ($appointment === null) {
            throw BookingException::appointmentNotFound($appointmentId);
        }

        return $appointment;
    }

    private function validateBookingWindow(DateTimeImmutable $scheduledAt): void
    {
        $now = new DateTimeImmutable();
        $hoursUntil = ($scheduledAt->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($hoursUntil < $this->config->minAdvanceHours) {
            throw BookingException::tooSoon($this->config->minAdvanceHours);
        }

        $daysUntil = ($scheduledAt->getTimestamp() - $now->getTimestamp()) / 86400;

        if ($daysUntil > $this->config->maxAdvanceDays) {
            throw BookingException::tooFarAhead($this->config->maxAdvanceDays);
        }
    }
}
