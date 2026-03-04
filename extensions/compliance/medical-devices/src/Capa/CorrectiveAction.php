<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Capa;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Corrective Action record per ISO 13485 Section 8.5.2.
 *
 * Corrective actions eliminate the cause of detected nonconformities
 * or other undesirable situations to prevent recurrence.
 *
 * @see ISO 13485:2016 Section 8.5.2
 */
#[Api(since: '1.0.0')]
final readonly class CorrectiveAction
{
    /**
     * @param list<string> $affectedDevices Device identifiers affected by this CAPA
     * @param list<string> $relatedComplaints Complaint IDs that triggered this CAPA
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $nonconformityDescription,
        public DateTimeImmutable $initiatedAt,
        public CapaStatus $status = CapaStatus::Initiated,
        public ?string $rootCauseAnalysis = null,
        public ?string $plannedAction = null,
        public ?DateTimeImmutable $actionDeadline = null,
        public ?string $implementationEvidence = null,
        public ?string $effectivenessVerification = null,
        public ?DateTimeImmutable $closedAt = null,
        public array $affectedDevices = [],
        public array $relatedComplaints = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'nonconformity_description' => $this->nonconformityDescription,
            'initiated_at' => $this->initiatedAt->format('Y-m-d'),
            'status' => $this->status->value,
        ];

        if ($this->rootCauseAnalysis !== null) {
            $data['root_cause_analysis'] = $this->rootCauseAnalysis;
        }

        if ($this->plannedAction !== null) {
            $data['planned_action'] = $this->plannedAction;
        }

        if ($this->actionDeadline !== null) {
            $data['action_deadline'] = $this->actionDeadline->format('Y-m-d');
        }

        if ($this->implementationEvidence !== null) {
            $data['implementation_evidence'] = $this->implementationEvidence;
        }

        if ($this->effectivenessVerification !== null) {
            $data['effectiveness_verification'] = $this->effectivenessVerification;
        }

        if ($this->closedAt !== null) {
            $data['closed_at'] = $this->closedAt->format('Y-m-d');
        }

        if ($this->affectedDevices !== []) {
            $data['affected_devices'] = $this->affectedDevices;
        }

        if ($this->relatedComplaints !== []) {
            $data['related_complaints'] = $this->relatedComplaints;
        }

        return $data;
    }
}
