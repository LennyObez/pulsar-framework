<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

use DateTimeImmutable;

/**
 * Patient entity.
 *
 * Represents a patient record. All fields containing PHI are classified
 * per the data classification configuration and encrypted at rest.
 */
final class Patient
{
    /**
     * @param non-empty-string        $id                Unique patient identifier
     * @param non-empty-string        $medicalRecordNumber Medical record number (encrypted)
     * @param non-empty-string        $firstName         Patient first name (PHI)
     * @param non-empty-string        $lastName          Patient last name (PHI)
     * @param DateTimeImmutable      $dateOfBirth       Date of birth (PHI)
     * @param non-empty-string|null   $gender            Patient gender
     * @param non-empty-string|null   $contactPhone      Contact phone number (PHI)
     * @param non-empty-string|null   $contactEmail      Contact email address (PHI)
     * @param PatientStatus           $status            Current patient status
     * @param DateTimeImmutable      $registeredAt      Registration timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $medicalRecordNumber,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly DateTimeImmutable $dateOfBirth,
        public readonly ?string $gender = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $contactEmail = null,
        public PatientStatus $status = PatientStatus::Active,
        public readonly DateTimeImmutable $registeredAt = new DateTimeImmutable(),
    ) {}

    public function fullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function isActive(): bool
    {
        return $this->status === PatientStatus::Active;
    }

    public function ageInYears(): int
    {
        return $this->dateOfBirth->diff(new DateTimeImmutable())->y;
    }
}
