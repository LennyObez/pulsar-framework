<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Surveillance;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Post-Market Clinical Follow-up (PMCF) report per MDR Article 61.
 *
 * Documents clinical evidence gathered after device placement on the market
 * to confirm safety and performance throughout the device's lifetime.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745 (Article 61, Annex XIV Part B)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PmcfReport
{
    /**
     * @param list<string> $clinicalStudyReferences References to supporting clinical studies
     * @param list<string> $literatureReferences    References to scientific literature
     */
    public function __construct(
        public string $id,
        public string $deviceIdentifier,
        public DateTimeImmutable $reportDate,
        public string $clinicalEvaluationSummary,
        public ?int $patientsSurveyed = null,
        public ?string $safetyConclusion = null,
        public ?string $performanceConclusion = null,
        public array $clinicalStudyReferences = [],
        public array $literatureReferences = [],
        public ?string $benefitRiskAssessment = null,
        public ?string $nextReviewDate = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'device_identifier' => $this->deviceIdentifier,
            'report_date' => $this->reportDate->format('Y-m-d'),
            'clinical_evaluation_summary' => $this->clinicalEvaluationSummary,
        ];

        if ($this->patientsSurveyed !== null) {
            $data['patients_surveyed'] = $this->patientsSurveyed;
        }

        if ($this->safetyConclusion !== null) {
            $data['safety_conclusion'] = $this->safetyConclusion;
        }

        if ($this->performanceConclusion !== null) {
            $data['performance_conclusion'] = $this->performanceConclusion;
        }

        if ($this->clinicalStudyReferences !== []) {
            $data['clinical_study_references'] = $this->clinicalStudyReferences;
        }

        if ($this->literatureReferences !== []) {
            $data['literature_references'] = $this->literatureReferences;
        }

        if ($this->benefitRiskAssessment !== null) {
            $data['benefit_risk_assessment'] = $this->benefitRiskAssessment;
        }

        if ($this->nextReviewDate !== null) {
            $data['next_review_date'] = $this->nextReviewDate;
        }

        return $data;
    }
}
