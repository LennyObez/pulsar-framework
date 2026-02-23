<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Legal case entity.
 *
 * Represents a legal matter or case with client association,
 * status tracking, and privilege management.
 */
final class LegalCase
{
    /**
     * @param non-empty-string        $id              Unique case identifier
     * @param non-empty-string        $caseNumber      Internal case number
     * @param non-empty-string        $title           Case title
     * @param non-empty-string        $clientId        Associated client identifier
     * @param non-empty-string        $practiceArea    Practice area (e.g., "litigation", "corporate")
     * @param CaseStatus              $status          Current case status
     * @param non-empty-string|null   $assignedAttorney Primary assigned attorney
     * @param non-empty-string|null   $courtReference  Court case reference number
     * @param \DateTimeImmutable      $openedAt        Case opening date
     * @param \DateTimeImmutable|null $closedAt        Case closing date
     */
    public function __construct(
        public readonly string $id,
        public readonly string $caseNumber,
        public readonly string $title,
        public readonly string $clientId,
        public readonly string $practiceArea,
        public CaseStatus $status = CaseStatus::Open,
        public readonly ?string $assignedAttorney = null,
        public readonly ?string $courtReference = null,
        public readonly \DateTimeImmutable $openedAt = new \DateTimeImmutable(),
        public readonly ?\DateTimeImmutable $closedAt = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === CaseStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === CaseStatus::Closed;
    }

    public function isOnHold(): bool
    {
        return $this->status === CaseStatus::OnHold;
    }
}
