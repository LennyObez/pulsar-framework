<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Configuration DTO for the AI governance extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiGovernanceConfig
{
    /**
     * @param bool $auditInvocations Whether to automatically audit all model invocations
     * @param bool $requireImpactAssessment Whether models must pass impact assessment before deployment
     * @param bool $requireModelCard Whether models must have a model card attached before deployment
     * @param bool $requireConsentForTrainingData Whether consent must be recorded for all training data
     * @param string $registryStore Store implementation ('memory' or a service class name)
     * @param string $dataGovernanceStore Store implementation ('memory' or a service class name)
     * @param string $explainabilityStore Store implementation ('memory' or a service class name)
     */
    public function __construct(
        public bool $auditInvocations = true,
        public bool $requireImpactAssessment = true,
        public bool $requireModelCard = false,
        public bool $requireConsentForTrainingData = true,
        public string $registryStore = 'memory',
        public string $dataGovernanceStore = 'memory',
        public string $explainabilityStore = 'memory',
    ) {}

    /**
     * Build from raw config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            auditInvocations: (bool) ($data['audit_invocations'] ?? true),
            requireImpactAssessment: (bool) ($data['require_impact_assessment'] ?? true),
            requireModelCard: (bool) ($data['require_model_card'] ?? false),
            requireConsentForTrainingData: (bool) ($data['require_consent_for_training_data'] ?? true),
            registryStore: is_string($data['registry_store'] ?? null) ? $data['registry_store'] : 'memory',
            dataGovernanceStore: is_string($data['data_governance_store'] ?? null) ? $data['data_governance_store'] : 'memory',
            explainabilityStore: is_string($data['explainability_store'] ?? null) ? $data['explainability_store'] : 'memory',
        );
    }
}
