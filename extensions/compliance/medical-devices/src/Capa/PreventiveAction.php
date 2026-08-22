<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Capa;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Preventive Action record per ISO 13485 Section 8.5.3.
 *
 * Preventive actions eliminate the causes of potential nonconformities
 * to prevent their occurrence. Triggered by trend analysis, risk assessment,
 * or proactive quality improvement.
 *
 * @see ISO 13485:2016 Section 8.5.3
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PreventiveAction
{
    /**
     * @param list<string> $affectedDevices Device identifiers affected by this action
     * @param list<string> $triggerSources What triggered this preventive action (e.g., trend analysis, audit finding)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $potentialNonconformityDescription,
        public DateTimeImmutable $initiatedAt,
        public CapaStatus $status = CapaStatus::Initiated,
        public ?string $riskAssessment = null,
        public ?string $plannedAction = null,
        public ?DateTimeImmutable $actionDeadline = null,
        public ?string $implementationEvidence = null,
        public ?string $effectivenessVerification = null,
        public ?DateTimeImmutable $closedAt = null,
        public array $affectedDevices = [],
        public array $triggerSources = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'potential_nonconformity_description' => $this->potentialNonconformityDescription,
            'initiated_at' => $this->initiatedAt->format('Y-m-d'),
            'status' => $this->status->value,
        ];

        if ($this->riskAssessment !== null) {
            $data['risk_assessment'] = $this->riskAssessment;
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

        if ($this->triggerSources !== []) {
            $data['trigger_sources'] = $this->triggerSources;
        }

        return $data;
    }
}
