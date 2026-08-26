<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Gate;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;

use function implode;
use function sprintf;

/**
 * Deployment gate requiring the pre-market obligations of a high-risk AI system.
 *
 * The EU AI Act (Regulation (EU) 2024/1689) conditions the placing of an
 * Article 6 high-risk system — an Annex I safety component or an Annex III use
 * case — on three things this extension already models:
 *
 * - Article 9 (Chapter III Section 2), risk management system — an impact
 *   assessment on record.
 * - Article 11 and Annex IV (Chapter III Section 2), technical documentation —
 *   an attached model card.
 * - Article 72(3), the post-market monitoring plan. It sits in Chapter IX, and
 *   it is still a precondition of deployment because Annex IV point 9 requires
 *   the technical documentation above to contain it — at least one registered
 *   monitoring hook.
 *
 * Models in the other three tiers pass untouched: this gate asks about the
 * duties of one tier and has no opinion about the rest.
 *
 * Wired unconditionally. Two of the three artefacts have optional gates of
 * their own (`require_impact_assessment`, `require_model_card`), and for a
 * high-risk system they are not optional — a model card gate switched off does
 * not make Article 11 stop applying, and `require_model_card` ships off.
 *
 * `evaluate()` records which obligations were unmet so `failureReason()` can
 * name them rather than reciting all three; the lifecycle manager reads the
 * reason immediately after the evaluation that produced it. Before any
 * evaluation the gate reports the full set, which is the honest answer to what
 * it requires.
 */
#[Internal]
final class HighRiskObligationsGate implements DeploymentGateInterface
{
    private const string ALL_OBLIGATIONS = 'an impact assessment on record (Article 9), '
        . 'an attached model card (Article 11 and Annex IV) '
        . 'and at least one registered monitoring hook (Article 72(3))';

    private string $unmetObligations = self::ALL_OBLIGATIONS;

    public function __construct(
        private readonly AiImpactAssessmentInterface $assessments,
        private readonly MonitoringHookRegistry $monitoringHooks,
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

        $unmet = [];

        if (! $this->assessments->hasAssessment($model->id)) {
            $unmet[] = 'an impact assessment on record (Article 9, risk management system)';
        }

        if ($model->card === null) {
            $unmet[] = 'an attached model card (Article 11 and Annex IV, technical documentation)';
        }

        if ($this->monitoringHooks->isEmpty()) {
            $unmet[] = 'at least one registered monitoring hook (Article 72(3), post-market monitoring plan)';
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
