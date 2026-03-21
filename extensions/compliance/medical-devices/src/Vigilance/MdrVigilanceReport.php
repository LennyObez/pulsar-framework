<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Vigilance;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Vigilance report for serious incidents per MDR Article 87.
 *
 * Extends the concept of incident reporting with MDR-specific fields for
 * regulatory submission to competent authorities.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745 (Article 87)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MdrVigilanceReport
{
    /**
     * @param list<string> $patientOutcomes Outcomes for affected patients
     */
    public function __construct(
        public string $id,
        public string $deviceIdentifier,
        public string $manufacturer,
        public DateTimeImmutable $incidentDate,
        public DateTimeImmutable $reportDate,
        public SeriousIncidentType $incidentType,
        public string $incidentDescription,
        public ?string $deviceLotNumber = null,
        public ?string $deviceSerialNumber = null,
        public array $patientOutcomes = [],
        public ?string $rootCauseAssessment = null,
        public ?string $correctiveActionTaken = null,
        public VigilanceReportStatus $status = VigilanceReportStatus::Initial,
        public ?string $competentAuthorityReference = null,
        public bool $isTrending = false,
    ) {}

    /**
     * Determine if this incident meets MDR criteria for a serious incident.
     *
     * Per MDR Article 2(65), a serious incident is one that directly or
     * indirectly led, might have led or might lead to death, temporary
     * or permanent serious deterioration of health, or a serious public health threat.
     */
    public function isSeriousIncident(): bool
    {
        return match ($this->incidentType) {
            SeriousIncidentType::Death,
            SeriousIncidentType::SeriousDeteriorationOfHealth,
            SeriousIncidentType::PublicHealthThreat => true,
            SeriousIncidentType::Other => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'device_identifier' => $this->deviceIdentifier,
            'manufacturer' => $this->manufacturer,
            'incident_date' => $this->incidentDate->format('Y-m-d'),
            'report_date' => $this->reportDate->format('Y-m-d'),
            'incident_type' => $this->incidentType->value,
            'incident_description' => $this->incidentDescription,
            'status' => $this->status->value,
            'is_serious' => $this->isSeriousIncident(),
            'is_trending' => $this->isTrending,
        ];

        if ($this->deviceLotNumber !== null) {
            $data['device_lot_number'] = $this->deviceLotNumber;
        }

        if ($this->deviceSerialNumber !== null) {
            $data['device_serial_number'] = $this->deviceSerialNumber;
        }

        if ($this->patientOutcomes !== []) {
            $data['patient_outcomes'] = $this->patientOutcomes;
        }

        if ($this->rootCauseAssessment !== null) {
            $data['root_cause_assessment'] = $this->rootCauseAssessment;
        }

        if ($this->correctiveActionTaken !== null) {
            $data['corrective_action_taken'] = $this->correctiveActionTaken;
        }

        if ($this->competentAuthorityReference !== null) {
            $data['competent_authority_reference'] = $this->competentAuthorityReference;
        }

        return $data;
    }
}
