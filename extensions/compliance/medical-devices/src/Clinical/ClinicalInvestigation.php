<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Clinical;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Clinical investigation record per MDR Article 62-82.
 *
 * Tracks clinical investigation planning, execution, and results
 * for medical devices requiring clinical evidence.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745 (Chapter VI)
 */
#[Api(since: '1.0.0')]
final readonly class ClinicalInvestigation
{
    /**
     * @param list<string> $ethicsCommitteeApprovals Ethics committee reference numbers
     */
    public function __construct(
        public string $id,
        public string $deviceIdentifier,
        public string $title,
        public string $sponsor,
        public ClinicalInvestigationStatus $status,
        public ?DateTimeImmutable $startDate = null,
        public ?DateTimeImmutable $endDate = null,
        public ?int $plannedSubjects = null,
        public ?int $enrolledSubjects = null,
        public ?string $primaryEndpoint = null,
        public ?string $investigationPlan = null,
        public array $ethicsCommitteeApprovals = [],
        public ?string $competentAuthorityNotification = null,
        public ?string $clinicalEvidenceSummary = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'device_identifier' => $this->deviceIdentifier,
            'title' => $this->title,
            'sponsor' => $this->sponsor,
            'status' => $this->status->value,
        ];

        if ($this->startDate !== null) {
            $data['start_date'] = $this->startDate->format('Y-m-d');
        }

        if ($this->endDate !== null) {
            $data['end_date'] = $this->endDate->format('Y-m-d');
        }

        if ($this->plannedSubjects !== null) {
            $data['planned_subjects'] = $this->plannedSubjects;
        }

        if ($this->enrolledSubjects !== null) {
            $data['enrolled_subjects'] = $this->enrolledSubjects;
        }

        if ($this->primaryEndpoint !== null) {
            $data['primary_endpoint'] = $this->primaryEndpoint;
        }

        if ($this->investigationPlan !== null) {
            $data['investigation_plan'] = $this->investigationPlan;
        }

        if ($this->ethicsCommitteeApprovals !== []) {
            $data['ethics_committee_approvals'] = $this->ethicsCommitteeApprovals;
        }

        if ($this->competentAuthorityNotification !== null) {
            $data['competent_authority_notification'] = $this->competentAuthorityNotification;
        }

        if ($this->clinicalEvidenceSummary !== null) {
            $data['clinical_evidence_summary'] = $this->clinicalEvidenceSummary;
        }

        return $data;
    }
}
