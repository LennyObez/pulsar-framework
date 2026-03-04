<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\ThirdParty;

use Pulsar\Api\Api;

/**
 * Result of a concentration risk analysis per DORA Article 29.
 *
 * Identifies situations where too many critical functions depend
 * on a single third-party provider, creating systemic risk.
 */
#[Api(since: '1.0.0')]
final readonly class ConcentrationRiskResult
{
    /**
     * @param list<string> $criticalFunctions Functions depending on this provider
     */
    public function __construct(
        public string $providerName,
        public int $dependentServiceCount,
        public array $criticalFunctions,
        public bool $isConcentrationRisk,
        public ?string $mitigationRecommendation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'provider_name' => $this->providerName,
            'dependent_service_count' => $this->dependentServiceCount,
            'critical_functions' => $this->criticalFunctions,
            'is_concentration_risk' => $this->isConcentrationRisk,
        ];

        if ($this->mitigationRecommendation !== null) {
            $data['mitigation_recommendation'] = $this->mitigationRecommendation;
        }

        return $data;
    }
}
