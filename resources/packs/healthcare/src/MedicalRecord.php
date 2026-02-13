<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

use DateTimeImmutable;

/**
 * Medical record entity.
 *
 * Represents a clinical record entry. Access to medical records is
 * logged per the PHI access logging configuration.
 */
final class MedicalRecord
{
    /**
     * @param non-empty-string        $id            Unique record identifier
     * @param non-empty-string        $patientId     Associated patient identifier
     * @param non-empty-string        $providerId    Treating provider identifier
     * @param non-empty-string        $recordType    Record type (e.g., "encounter", "lab_result", "imaging")
     * @param non-empty-string        $content       Clinical content (encrypted, PHI)
     * @param non-empty-string|null   $diagnosis     Diagnosis code or description (PHI)
     * @param non-empty-string|null   $treatmentPlan Treatment plan (PHI)
     * @param RecordConfidentiality   $confidentiality Confidentiality classification
     * @param DateTimeImmutable      $recordDate    Date of the clinical event
     * @param DateTimeImmutable      $createdAt     Record creation timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $patientId,
        public readonly string $providerId,
        public readonly string $recordType,
        public readonly string $content,
        public readonly ?string $diagnosis = null,
        public readonly ?string $treatmentPlan = null,
        public RecordConfidentiality $confidentiality = RecordConfidentiality::Normal,
        public readonly DateTimeImmutable $recordDate = new DateTimeImmutable(),
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}

    public function isRestricted(): bool
    {
        return $this->confidentiality === RecordConfidentiality::Restricted;
    }

    public function requiresSpecialConsent(): bool
    {
        return $this->confidentiality === RecordConfidentiality::Restricted
            || $this->confidentiality === RecordConfidentiality::VeryRestricted;
    }
}
