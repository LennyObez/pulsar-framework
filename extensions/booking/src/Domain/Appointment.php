<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Immutable appointment entity with state machine enforcement.
 */
#[Api(since: '1.0.0')]
final readonly class Appointment
{
    public function __construct(
        public string $id,
        public string $bookingNumber,
        public string $serviceId,
        public string $customerId,
        public string $customerName,
        public string $customerEmail,
        public string $customerPhone,
        public AppointmentStatus $status,
        public DateTimeImmutable $scheduledAt,
        public int $duration,
        public ?Money $depositAmount,
        public bool $depositPaid,
        public string $notes,
        public bool $reminderSent,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Transition to a new status, validating the state machine.
     *
     * @throws BookingException If the transition is invalid
     */
    #[NoDiscard]
    public function transitionTo(AppointmentStatus $newStatus): self
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw BookingException::invalidTransition(
                $this->status->value,
                $newStatus->value,
            );
        }

        return clone($this, [
            'status' => $newStatus,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Mark the deposit as paid.
     *
     * @throws BookingException If already paid or no deposit required
     */
    #[NoDiscard]
    public function markDepositPaid(): self
    {
        if ($this->depositPaid) {
            throw BookingException::depositAlreadyPaid($this->bookingNumber);
        }

        if ($this->depositAmount === null || $this->depositAmount->isZero()) {
            throw BookingException::noDepositRequired($this->bookingNumber);
        }

        $appointment = clone($this, [
            'depositPaid' => true,
            'updatedAt' => new DateTimeImmutable(),
        ]);

        if ($appointment->status === AppointmentStatus::Confirmed) {
            return $appointment->transitionTo(AppointmentStatus::DepositPaid);
        }

        return $appointment;
    }

    /**
     * Mark that a reminder has been sent.
     */
    #[NoDiscard]
    public function markReminderSent(): self
    {
        return clone($this, [
            'reminderSent' => true,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Reschedule to a new date/time.
     *
     * @throws BookingException If the appointment cannot be rescheduled
     */
    #[NoDiscard]
    public function reschedule(DateTimeImmutable $newScheduledAt, int $newDuration): self
    {
        if (!$this->status->canTransitionTo(AppointmentStatus::Rescheduled)) {
            throw BookingException::cannotReschedule($this->bookingNumber);
        }

        return clone($this, [
            'scheduledAt' => $newScheduledAt,
            'duration' => $newDuration,
            'status' => AppointmentStatus::Rescheduled,
            'reminderSent' => false,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Get the end time of this appointment.
     */
    #[NoDiscard]
    public function endTime(): DateTimeImmutable
    {
        return $this->scheduledAt->modify("+{$this->duration} minutes");
    }

    /**
     * Check if this appointment is in the past.
     */
    public function isPast(): bool
    {
        return $this->scheduledAt < new DateTimeImmutable();
    }

    /**
     * Check if this appointment needs a deposit.
     */
    public function needsDeposit(): bool
    {
        return $this->depositAmount !== null
            && !$this->depositAmount->isZero()
            && !$this->depositPaid;
    }
}
