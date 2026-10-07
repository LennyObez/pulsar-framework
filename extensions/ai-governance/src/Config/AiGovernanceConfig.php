<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Support\Coerce;

use function array_column;

/**
 * Configuration DTO for the AI governance extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiGovernanceConfig
{
    /**
     * The durable store, and the default for every store key since rc.12.
     *
     * It used to be {@see MEMORY}, which meant a deployment that enabled this
     * extension and changed nothing kept its AI model inventory, its impact
     * assessments, its data provenance and its explanations in the memory of one
     * worker. ISO 42001:2023 Clause 7.5 asks for documented information and
     * Clause 9.1 asks for retained evidence; neither is discharged by a record
     * that is gone at the next restart, so the default that retains nothing is no
     * longer the default. A deployment that genuinely wants the development store
     * says so, per key, and the compliance report then reports what it said.
     */
    public const string DATABASE = 'database';

    /**
     * The development store: usable, and evidence of nothing after a restart.
     */
    public const string MEMORY = 'memory';

    /**
     * @param bool $auditInvocations Whether to automatically audit all model invocations
     * @param bool $requireImpactAssessment Whether models must pass impact assessment before deployment
     * @param bool $requireModelCard Whether models must have a model card attached before deployment
     * @param bool $requireConsentForTrainingData Whether consent must be recorded for all training data
     * @param float $impactRiskThreshold Maximum acceptable impact risk score (0.0-10.0) a model may
     *        carry and still pass the deployment gate when $requireImpactAssessment is enabled
     * @param string $registryStore Store implementation ('database', 'memory', or a service class name)
     * @param string $impactAssessmentStore Store implementation ('database', 'memory', or a service class name)
     * @param string $dataGovernanceStore Store implementation ('database', 'memory', or a service class name)
     * @param string $explainabilityStore Store implementation ('database', 'memory', or a service class name)
     * @param string $monitoringRecordStore Store implementation ('database', 'memory', or a service class name)
     * @param string $transparencyStore Store implementation ('database', 'memory', or a service class name)
     *        for the Article 50 register. This key does NOT decide whether the transparency
     *        contract is bound — it always is, because Article 50 has applied since 2 August
     *        2026 and a deployment does not get to switch a live duty off by not wiring it.
     *        It decides only where a declared position is kept. 'memory' is the right answer
     *        for a deployment that declares every surface in code on every boot, and the wrong
     *        one for a deployment whose surfaces are declared by an operator at runtime, which
     *        the contract permits and which loses every declaration at the next restart
     * @param string $oversightStore Store implementation ('database', 'memory', or a service
     *        class name) for the EU AI Act Article 14 and Article 26(2) human oversight record
     * @param AiActorRole|null $actorRole What this deployment is, under the EU AI Act, with
     *        respect to the AI systems it runs — provider (Article 3(3)), deployer
     *        (Article 3(4)), or both. It decides WHICH obligations apply, so there is no
     *        default: null means the deployment has not declared one, and a high-risk
     *        deployment is then refused naming both sets rather than being assumed into one.
     *        An individual system overrides this through AiModel::$actorRole, which is what
     *        an organisation providing one model and deploying another needs
     */
    public function __construct(
        public bool $auditInvocations = true,
        public bool $requireImpactAssessment = true,
        public bool $requireModelCard = false,
        public bool $requireConsentForTrainingData = true,
        public float $impactRiskThreshold = 7.0,
        public string $registryStore = self::DATABASE,
        public string $impactAssessmentStore = self::DATABASE,
        public string $dataGovernanceStore = self::DATABASE,
        public string $explainabilityStore = self::DATABASE,
        public string $monitoringRecordStore = self::DATABASE,
        public string $transparencyStore = self::DATABASE,
        public string $oversightStore = self::DATABASE,
        public ?AiActorRole $actorRole = null,
    ) {}

    /**
     * Build from raw config array.
     *
     * @param array{
     *     audit_invocations?: bool|int|string,
     *     require_impact_assessment?: bool|int|string,
     *     require_model_card?: bool|int|string,
     *     require_consent_for_training_data?: bool|int|string,
     *     impact_risk_threshold?: float|int|string,
     *     registry_store?: string,
     *     impact_assessment_store?: string,
     *     data_governance_store?: string,
     *     explainability_store?: string,
     *     monitoring_record_store?: string,
     *     transparency_store?: string,
     *     oversight_store?: string,
     *     actor_role?: string,
     * } $data
     *
     * @throws AiGovernanceException when actor_role names something the Act does not define
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            auditInvocations: (bool) ($data['audit_invocations'] ?? true),
            requireImpactAssessment: (bool) ($data['require_impact_assessment'] ?? true),
            requireModelCard: (bool) ($data['require_model_card'] ?? false),
            requireConsentForTrainingData: (bool) ($data['require_consent_for_training_data'] ?? true),
            impactRiskThreshold: Coerce::nullableFloat($data['impact_risk_threshold'] ?? null) ?? 7.0,
            registryStore: Coerce::string($data['registry_store'] ?? null, self::DATABASE),
            impactAssessmentStore: Coerce::string($data['impact_assessment_store'] ?? null, self::DATABASE),
            dataGovernanceStore: Coerce::string($data['data_governance_store'] ?? null, self::DATABASE),
            explainabilityStore: Coerce::string($data['explainability_store'] ?? null, self::DATABASE),
            monitoringRecordStore: Coerce::string($data['monitoring_record_store'] ?? null, self::DATABASE),
            transparencyStore: Coerce::string($data['transparency_store'] ?? null, self::DATABASE),
            oversightStore: Coerce::string($data['oversight_store'] ?? null, self::DATABASE),
            actorRole: self::actorRole($data['actor_role'] ?? null),
        );
    }

    /**
     * The declared EU AI Act role, or absence.
     *
     * An absent or blank key is absence, which is a real state: the deployment
     * has not said what it is, and the high-risk gate refuses rather than
     * guessing. A key holding something the Act does not define RAISES instead of
     * degrading to absence — a misspelt role must not resolve to the state in
     * which no obligation set is enforced, or a typo becomes an exemption.
     *
     * @throws AiGovernanceException when the value is not one of the Act's roles
     */
    private static function actorRole(mixed $value): ?AiActorRole
    {
        $raw = Coerce::string($value, '');

        if ($raw === '') {
            return null;
        }

        $role = AiActorRole::tryFrom($raw);

        if (! $role instanceof AiActorRole) {
            throw AiGovernanceException::unknownActorRole(
                $raw,
                array_column(AiActorRole::cases(), 'value'),
            );
        }

        return $role;
    }
}
