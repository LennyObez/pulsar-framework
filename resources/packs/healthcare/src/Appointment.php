<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Appointment entity.
 *
 * Represents a scheduled clinical appointment between a patient and provider.
 */
final class Appointment
{
    /**
     * @param non-empty-string        $id           Unique appointment identifier
     * @param non-empty-string        $patientId    Associated patient identifier
     * @param non-empty-string        $providerId   Treating provider identifier
     * @param non-empty-string        $facilityId   Facility identifier
     * @param non-empty-string        $appointmentType Type of appointment
     * @param AppointmentStatus       $status       Current appointment status
     * @param \DateTimeImmutable      $scheduledAt  Scheduled date and time
     * @param int                     $durationMinutes Expected duration in minutes
     * @param non-empty-string|null   $notes        Appointment notes (PHI)
     * @param \DateTimeImmutable      $createdAt    Creation timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $patientId,
        public readonly string $providerId,
        public readonly string $facilityId,
        public readonly string $appointmentType,
        public AppointmentStatus $status = AppointmentStatus::Scheduled,
        public readonly \DateTimeImmutable $scheduledAt = new \DateTimeImmutable(),
        public readonly int $durationMinutes = 30,
        public readonly ?string $notes = null,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    public function isUpcoming(): bool
    {
        return $this->status === AppointmentStatus::Scheduled
            && $this->scheduledAt > new \DateTimeImmutable();
    }

    public function isPast(): bool
    {
        return $this->scheduledAt < new \DateTimeImmutable();
    }
}
