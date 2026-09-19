<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

/**
 * Immutable DTO representing a registered AI model.
 *
 * Captures metadata required by ISO 42001:2023 Clause 8.2 for AI system
 * documentation including model cards, risk classification, and lifecycle state.
 *
 * `$provider` and `$actorRole` are different things and the names invite the
 * confusion, so read them together once. `$provider` is WHO MADE THE MODEL — an
 * organisation or service name, free text, documentation. `$actorRole` is WHAT
 * THIS DEPLOYMENT IS with respect to it under the EU AI Act, and it decides which
 * obligations apply: a provider under Article 3(3) owes Articles 9, 11 and 72(3);
 * a deployer under Article 3(4) owes Article 26 instead. A record can name
 * `provider: 'acme'` and hold `actorRole: Deployer`, and for most deployments of
 * a bought-in model that is exactly the right pair.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiModel
{
    /**
     * @param non-empty-string $id Unique model identifier
     * @param non-empty-string $name Human-readable model name
     * @param non-empty-string $version Model version string
     * @param non-empty-string $provider Organization or service providing the model
     * @param non-empty-string $type Model type (e.g., 'llm', 'classifier', 'regressor', 'generative')
     * @param ModelCard|null $card Structured documentation of capabilities, limitations, biases
     * @param AiActorRole|null $actorRole What this deployment is with respect to this system
     *        under the EU AI Act. Null means UNDECLARED, which is not a role and is not a
     *        default: the deployment has not said whether it is the provider or the deployer,
     *        and until it does neither obligation set can be enforced. A deployment-wide
     *        answer can be given once in `ai_governance.actor_role`; this field overrides it
     *        for one system, which is what an organisation that provides one model and
     *        deploys another needs
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $provider,
        public string $type,
        public AiModelRiskLevel $riskLevel,
        public AiModelStatus $status,
        public DateTimeImmutable $registeredAt,
        public ?ModelCard $card = null,
        public ?AiActorRole $actorRole = null,
    ) {}

    /**
     * Create a new model with a different status.
     */
    #[NoDiscard]
    public function withStatus(AiModelStatus $status): self
    {
        return clone($this, ['status' => $status]);
    }

    /**
     * Create a new model with a different risk level.
     */
    #[NoDiscard]
    public function withRiskLevel(AiModelRiskLevel $riskLevel): self
    {
        return clone($this, ['riskLevel' => $riskLevel]);
    }

    /**
     * Create a new model with an attached model card.
     */
    #[NoDiscard]
    public function withCard(ModelCard $card): self
    {
        return clone($this, ['card' => $card]);
    }

    /**
     * Create a new model declaring what this deployment is with respect to it.
     *
     * Article 25 is the reason this exists as a transition rather than only as a
     * constructor argument: a deployer BECOMES a provider of a high-risk system
     * once it puts its name on one already on the market, substantially modifies
     * one, or repurposes a system into the high-risk tier. Nothing in code can
     * observe that happening, so the deployment re-declares the role when it does.
     */
    #[NoDiscard]
    public function withActorRole(AiActorRole $actorRole): self
    {
        return clone($this, ['actorRole' => $actorRole]);
    }

    /**
     * The role in force for this system, falling back to the deployment's own.
     *
     * The precedence is stated once, here, so that every gate consulting a role
     * resolves it the same way: what this system declares, else what the
     * deployment declared for all of them, else nothing. Nothing is a real
     * answer — see `$actorRole` — and callers must handle it rather than
     * substituting a role of their own.
     */
    #[NoDiscard]
    public function actorRoleOr(?AiActorRole $deploymentRole): ?AiActorRole
    {
        return $this->actorRole ?? $deploymentRole;
    }
}
