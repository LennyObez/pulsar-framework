<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Gate;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistryInterface;

use function implode;
use function sprintf;

/**
 * Deployment gate requiring the obligations of a high-risk AI system — the ones
 * that actually apply to this deployment.
 *
 * WHAT CHANGED, AND WHY IT WAS WRONG BEFORE. This gate used to demand an impact
 * assessment, a model card and a registered monitoring hook from every high-risk
 * model, whoever was deploying it and in whatever capacity. Those three artefacts
 * stand for Article 9 (risk management system), Article 11 with Annex IV
 * (technical documentation) and Article 72(3) (post-market monitoring plan), and
 * every one of them is a PROVIDER obligation under Article 16. A deployer does
 * not draw up Annex IV documentation for a model it bought; Article 26 asks it
 * for something else entirely — use in accordance with the instructions for use,
 * human oversight assigned to natural persons with the necessary competence,
 * training and authority, input data governance, monitoring, and six months of
 * retained logs. So the old gate refused a bank's deployment of a third-party
 * credit-scoring model for failing duties the bank does not owe, while checking
 * none of the duties it does. A gate that cannot tell which set it is enforcing
 * is not enforcing either one.
 *
 * WHAT IT ENFORCES NOW, per limb:
 *
 * - PROVIDER limb (Article 3(3) roles): an impact assessment on record
 *   (Article 9), an attached model card (Article 11 and Annex IV), and at least
 *   one registered monitoring hook (Article 72(3), which sits in Chapter IX and
 *   is still pre-market because Annex IV point 9 requires the technical
 *   documentation to contain the plan).
 * - DEPLOYER limb (Article 3(4) roles): at least one natural person assigned to
 *   oversee the system, holding a stated competence, a stated authority and the
 *   two Article 14(4) capacities that must be exercisable — Article 26(2).
 * - BOTH, for {@see AiActorRole::ProviderAndDeployer}, which is also the position
 *   Article 25 moves a deployer into once it puts its name on a high-risk system,
 *   substantially modifies one, or repurposes a system into the tier.
 *
 * WHAT THE DEPLOYER LIMB DOES NOT ENFORCE, said plainly because a limb that
 * silently checked one thing out of six would be worse than none. Article 26 has
 * eight paragraphs. This gate reaches 26(2) — the assignment of oversight — and
 * nothing else: 26(1) use per the instructions for use, 26(4) input data
 * relevance, 26(5) monitoring and the suspension duty, 26(6) log retention, 26(7)
 * the notice to workers, 26(8) registration, and the Article 27 fundamental
 * rights impact assessment all remain operator responsibilities in the AI Act
 * mapping. That is the same shape the provider limb has always had — it reaches
 * three of the Article 16 obligations and the mapping carries the rest — and it
 * is honest for the same reason: what this gate refuses, it refuses on evidence
 * it holds.
 *
 * AN UNDECLARED ROLE FAILS, and it fails in this gate rather than in one of its
 * own. There is no third gate asking "have you declared a role", because a role
 * is not an obligation: it is the question that decides which obligations apply,
 * and the honest place to raise it is where the obligations would have been
 * enforced. A separate gate would refuse the deployment twice for one omission
 * and leave a reader to work out that the two messages are the same complaint.
 *
 * NOTHING IS ASSUMED FROM ABSENCE. The gate does not fall back to the provider
 * set, which was the old behaviour and is the tempting one: it demands more, so
 * it looks safe. It is not safe, it is wrong — it tells a deployer that its
 * conformity depends on documentation it will never hold, and a gate that can be
 * satisfied only by lying about your role teaches deployments to lie about
 * their role.
 *
 * Models in the other three tiers pass untouched. This gate asks about the duties
 * of one tier and has no opinion about the rest, and it says nothing at all about
 * a prohibited practice: {@see ProhibitedPracticeGate} refuses those, naming
 * Article 5, and adding a role complaint on top would suggest that declaring one
 * could unblock a ban.
 *
 * `evaluate()` records which obligations were unmet so `failureReason()` can name
 * them rather than reciting every limb; the lifecycle manager reads the reason
 * immediately after the evaluation that produced it. Before any evaluation the
 * gate reports what it requires of a deployment that has declared nothing, which
 * is the honest answer to what it requires.
 */
#[Internal]
final class HighRiskObligationsGate implements DeploymentGateInterface
{
    private const string PROVIDER_OBLIGATIONS = 'an impact assessment on record (Article 9), '
        . 'an attached model card (Article 11 and Annex IV) '
        . 'and at least one registered monitoring hook (Article 72(3))';

    private const string DEPLOYER_OBLIGATIONS = 'human oversight assigned to at least one natural '
        . 'person with the necessary competence and authority (Article 26(2))';

    private const string ROLE_UNDECLARED = 'a declared EU AI Act role for this system. A provider '
        . '(Article 3(3)) must show ' . self::PROVIDER_OBLIGATIONS . '; a deployer (Article 3(4)) '
        . 'must show ' . self::DEPLOYER_OBLIGATIONS . '. This deployment has declared neither role, '
        . 'so neither set can be enforced';

    private string $unmetObligations = self::ROLE_UNDECLARED;

    /**
     * @param AiActorRole|null $deploymentRole the role this deployment declared for every
     *        system it has not overridden, from `ai_governance.actor_role`. Null when the
     *        deployment declared none, which is not a role and is not a default
     */
    public function __construct(
        private readonly AiImpactAssessmentInterface $assessments,
        private readonly MonitoringHookRegistryInterface $monitoringHooks,
        private readonly HumanOversightInterface $oversight,
        private readonly ?AiActorRole $deploymentRole = null,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'high_risk_obligations';
    }

    #[Override]
    public function evaluate(AiModel $model): bool
    {
        if (! $model->riskLevel->carriesHighRiskObligations()) {
            return true;
        }

        $role = $model->actorRoleOr($this->deploymentRole);

        if (! $role instanceof AiActorRole) {
            $this->unmetObligations = self::ROLE_UNDECLARED;

            return false;
        }

        $unmet = [];

        if ($role->bearsProviderObligations()) {
            if (! $this->assessments->hasAssessment($model->id)) {
                $unmet[] = 'an impact assessment on record (Article 9, risk management system)';
            }

            if ($model->card === null) {
                $unmet[] = 'an attached model card (Article 11 and Annex IV, technical documentation)';
            }

            if ($this->monitoringHooks->isEmpty()) {
                $unmet[] = 'at least one registered monitoring hook (Article 72(3), post-market monitoring plan)';
            }
        }

        if ($role->bearsDeployerObligations() && ! $this->oversight->isOverseen($model->id)) {
            $unmet[] = 'human oversight assigned to a natural person with a stated competence and '
                . 'authority, able to disregard or reverse the output and to interrupt the '
                . 'operation (Article 26(2), read with Article 14(4)(d) and (e))';
        }

        if ($unmet === []) {
            return true;
        }

        $this->unmetObligations = implode(', ', $unmet);

        return false;
    }

    #[Override]
    public function failureReason(): string
    {
        return sprintf(
            'A high-risk AI system may not be deployed without %s.',
            $this->unmetObligations,
        );
    }
}
