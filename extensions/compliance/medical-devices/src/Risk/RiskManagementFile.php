<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Risk;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function count;

/**
 * Risk management file per ISO 14971.
 *
 * The risk management file is the collection of all risk management
 * records and documents produced throughout the device lifecycle.
 *
 * NOTE: ISO 14971 requires organizational processes (risk management
 * plan, risk analysis, risk evaluation, risk control, overall residual
 * risk evaluation, production and post-production review). This DTO
 * provides tooling support for documenting these activities.
 *
 * @see ISO 14971:2019 Medical devices: Application of risk management
 */
#[Api(since: '1.0.0')]
final readonly class RiskManagementFile
{
    /**
     * @param list<HazardEntry>  $hazards  Identified hazards and their risk controls
     */
    public function __construct(
        public string $id,
        public string $deviceIdentifier,
        public string $deviceName,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastReviewDate = null,
        public ?string $riskManagementPlan = null,
        public array $hazards = [],
        public ?string $overallResidualRiskAcceptability = null,
        public ?string $benefitRiskAnalysis = null,
    ) {}

    /**
     * Count hazards by risk level.
     *
     * @return array{high: int, medium: int, low: int, acceptable: int}
     */
    public function hazardSummary(): array
    {
        $summary = ['high' => 0, 'medium' => 0, 'low' => 0, 'acceptable' => 0];

        foreach ($this->hazards as $hazard) {
            $level = $hazard->residualRiskLevel->value;
            if (isset($summary[$level])) {
                ++$summary[$level];
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'device_identifier' => $this->deviceIdentifier,
            'device_name' => $this->deviceName,
            'created_at' => $this->createdAt->format('Y-m-d'),
            'hazard_count' => count($this->hazards),
            'hazard_summary' => $this->hazardSummary(),
        ];

        if ($this->lastReviewDate !== null) {
            $data['last_review_date'] = $this->lastReviewDate->format('Y-m-d');
        }

        if ($this->riskManagementPlan !== null) {
            $data['risk_management_plan'] = $this->riskManagementPlan;
        }

        if ($this->hazards !== []) {
            $data['hazards'] = array_map(
                static fn(HazardEntry $h): array => $h->toArray(),
                $this->hazards,
            );
        }

        if ($this->overallResidualRiskAcceptability !== null) {
            $data['overall_residual_risk_acceptability'] = $this->overallResidualRiskAcceptability;
        }

        if ($this->benefitRiskAnalysis !== null) {
            $data['benefit_risk_analysis'] = $this->benefitRiskAnalysis;
        }

        return $data;
    }
}
