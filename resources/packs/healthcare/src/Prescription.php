<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Prescription entity.
 *
 * Represents a medication prescription with dosage instructions.
 * All prescription data is classified as PHI.
 */
final class Prescription
{
    /**
     * @param non-empty-string        $id              Unique prescription identifier
     * @param non-empty-string        $patientId       Associated patient identifier
     * @param non-empty-string        $prescriberId    Prescribing provider identifier
     * @param non-empty-string        $medicationName  Medication name
     * @param non-empty-string        $dosage          Dosage instructions
     * @param non-empty-string        $frequency       Frequency (e.g., "twice daily")
     * @param int                     $quantityDays    Quantity in days
     * @param int                     $refillsRemaining Number of refills remaining
     * @param PrescriptionStatus      $status          Current prescription status
     * @param \DateTimeImmutable      $prescribedAt    Prescription date
     * @param \DateTimeImmutable|null $expiresAt       Expiration date
     */
    public function __construct(
        public readonly string $id,
        public readonly string $patientId,
        public readonly string $prescriberId,
        public readonly string $medicationName,
        public readonly string $dosage,
        public readonly string $frequency,
        public readonly int $quantityDays = 30,
        public int $refillsRemaining = 0,
        public PrescriptionStatus $status = PrescriptionStatus::Active,
        public readonly \DateTimeImmutable $prescribedAt = new \DateTimeImmutable(),
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === PrescriptionStatus::Active && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function hasRefills(): bool
    {
        return $this->refillsRemaining > 0;
    }
}
