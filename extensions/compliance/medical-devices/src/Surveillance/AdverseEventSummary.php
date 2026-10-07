<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Surveillance;

use Pulsar\Api\Api;

/**
 * Summary of an adverse event for inclusion in PMS reports.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdverseEventSummary
{
    public function __construct(
        public string $eventType,
        public int $occurrenceCount,
        public string $severityAssessment,
        public ?string $patientOutcome = null,
        public ?string $rootCauseAnalysis = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'event_type' => $this->eventType,
            'occurrence_count' => $this->occurrenceCount,
            'severity_assessment' => $this->severityAssessment,
        ];

        if ($this->patientOutcome !== null) {
            $data['patient_outcome'] = $this->patientOutcome;
        }

        if ($this->rootCauseAnalysis !== null) {
            $data['root_cause_analysis'] = $this->rootCauseAnalysis;
        }

        return $data;
    }
}
