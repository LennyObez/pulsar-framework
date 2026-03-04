<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Risk;

use Pulsar\Api\Api;

/**
 * A single hazard entry in a risk management file.
 *
 * Documents the identified hazard, its severity/probability, risk controls
 * applied, and the resulting residual risk level.
 */
#[Api(since: '1.0.0')]
final readonly class HazardEntry
{
    /**
     * @param list<string> $riskControls Applied risk control measures
     */
    public function __construct(
        public string $hazardId,
        public string $hazardDescription,
        public string $harmDescription,
        public RiskSeverity $severity,
        public RiskProbability $probability,
        public RiskLevel $initialRiskLevel,
        public array $riskControls = [],
        public RiskLevel $residualRiskLevel = RiskLevel::Acceptable,
        public ?string $verificationOfControlEffectiveness = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'hazard_id' => $this->hazardId,
            'hazard_description' => $this->hazardDescription,
            'harm_description' => $this->harmDescription,
            'severity' => $this->severity->value,
            'probability' => $this->probability->value,
            'initial_risk_level' => $this->initialRiskLevel->value,
            'residual_risk_level' => $this->residualRiskLevel->value,
        ];

        if ($this->riskControls !== []) {
            $data['risk_controls'] = $this->riskControls;
        }

        if ($this->verificationOfControlEffectiveness !== null) {
            $data['verification_of_control_effectiveness'] = $this->verificationOfControlEffectiveness;
        }

        return $data;
    }
}
