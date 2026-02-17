<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Incident;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * ICT incident record per DORA Articles 17-23.
 *
 * Documents ICT-related incidents with classification, timeline tracking,
 * and regulatory reporting deadlines.
 *
 * Reporting timeline per DORA Article 19:
 * - Initial notification: within 4 hours of classification as major
 * - Intermediate report: within 72 hours
 * - Final report: within 1 month
 */
#[Api(since: '1.0.0')]
final readonly class IctIncidentRecord
{
    /**
     * @param list<string> $affectedServices   Services impacted by the incident
     * @param list<string> $affectedClients    Client segments affected
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $detectedAt,
        public string $description,
        public string $severity,
        public IctIncidentClassification $classification,
        public IncidentReportingPhase $reportingPhase = IncidentReportingPhase::Detection,
        public array $affectedServices = [],
        public array $affectedClients = [],
        public ?int $estimatedClientsAffected = null,
        public ?string $dataLossDescription = null,
        public ?float $estimatedFinancialImpact = null,
        public ?string $geographicalSpread = null,
        public ?DateTimeImmutable $resolvedAt = null,
        public ?string $rootCause = null,
        public ?string $remediationAction = null,
    ) {}

    /**
     * Check if the initial notification deadline (4 hours) has been exceeded.
     */
    public function isInitialNotificationOverdue(DateTimeImmutable $now): bool
    {
        if ($this->classification !== IctIncidentClassification::Major) {
            return false;
        }

        $hoursSinceDetection = ($now->getTimestamp() - $this->detectedAt->getTimestamp()) / 3600;

        return $hoursSinceDetection > 4 && $this->reportingPhase === IncidentReportingPhase::Detection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'detected_at' => $this->detectedAt->format('Y-m-d\TH:i:sP'),
            'description' => $this->description,
            'severity' => $this->severity,
            'classification' => $this->classification->value,
            'reporting_phase' => $this->reportingPhase->value,
        ];

        if ($this->affectedServices !== []) {
            $data['affected_services'] = $this->affectedServices;
        }

        if ($this->affectedClients !== []) {
            $data['affected_clients'] = $this->affectedClients;
        }

        if ($this->estimatedClientsAffected !== null) {
            $data['estimated_clients_affected'] = $this->estimatedClientsAffected;
        }

        if ($this->dataLossDescription !== null) {
            $data['data_loss_description'] = $this->dataLossDescription;
        }

        if ($this->estimatedFinancialImpact !== null) {
            $data['estimated_financial_impact'] = $this->estimatedFinancialImpact;
        }

        if ($this->geographicalSpread !== null) {
            $data['geographical_spread'] = $this->geographicalSpread;
        }

        if ($this->resolvedAt !== null) {
            $data['resolved_at'] = $this->resolvedAt->format('Y-m-d\TH:i:sP');
        }

        if ($this->rootCause !== null) {
            $data['root_cause'] = $this->rootCause;
        }

        if ($this->remediationAction !== null) {
            $data['remediation_action'] = $this->remediationAction;
        }

        return $data;
    }
}
