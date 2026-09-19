<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Monitoring;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringResult;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

use function count;
use function implode;
use function sprintf;

/**
 * The monitoring hook this framework can honestly ship: whether the obligations
 * a deployed model was let through on still hold.
 *
 * WHAT WAS WRONG BEFORE. `MonitoringHookInterface` had zero implementations
 * anywhere in the tree — the interface, the registry that holds hooks, and one
 * test double were all there was — so ISO 42001 Clause 9.1 rested on a file
 * describing monitoring, and the high-risk deployment gate could never pass on
 * any deployment because Article 72(3) asks for a registered hook and no
 * deployment had one.
 *
 * WHAT THIS ONE MEASURES, and the boundary matters more than the list. It does
 * NOT measure model performance, drift, or bias emergence: those are properties
 * of a model's outputs against ground truth, the framework never sees either,
 * and a hook that scored them from metadata would be manufacturing the finding an
 * assessor is supposed to be shown. What it measures is what this deployment
 * genuinely holds — the governance record of a model that is in service — and
 * whether it still satisfies the conditions the model was admitted under:
 *
 *  1. the model is not classified as an EU AI Act Article 5 prohibited practice;
 *  2. where the model's tier carries the Article 6 high-risk obligations, an
 *     impact assessment is still on record (Article 9) and a model card is still
 *     attached (Article 11 and Annex IV);
 *  3. where any impact assessment is on record, its computed risk score is still
 *     at or below the threshold the deployment set.
 *
 * WHY THAT IS MONITORING AND NOT A SECOND DEPLOYMENT GATE. The gates run once, at
 * the moment a model transitions into production, against the record as it stood
 * then. Every input above can change afterwards and nothing re-reads them: a
 * finding added to an assessment raises the risk score of a model already
 * serving traffic, a reclassification moves a model into a tier whose obligations
 * it never met, and a card can be detached. Article 72 calls the resulting duty
 * post-market monitoring for exactly this reason — the obligations do not stop
 * applying when the system is placed on the market — and Clause 9.1 asks for the
 * results to be retained so the change is visible as a sequence rather than as a
 * current status.
 *
 * WHAT AN INTEGRATOR STILL OWES. This hook is a floor and not a monitoring plan.
 * Accuracy against ground truth, drift, bias emergence, and the operational
 * signals of the serving path all live where the framework cannot see, and a
 * deployment subject to Article 72(3) is expected to register hooks for them
 * alongside this one. The hook registry takes as many as are added.
 */
#[Internal(reason: 'Shipped monitoring hook; register more via AiLifecycleManagerInterface::addMonitoringHook()')]
final readonly class GovernanceConformityHook implements MonitoringHookInterface
{
    /** The name every retained record of this check carries. */
    public const string NAME = 'governance_conformity';

    public function __construct(
        private AiImpactAssessmentInterface $assessments,
        private float $impactRiskThreshold,
    ) {}

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string, mixed> $context Unused: every input this check reads is
     *        a fact the governance record holds, and a check that took its inputs
     *        from the caller would be grading the caller
     */
    #[Override]
    public function check(AiModel $model, array $context = []): MonitoringResult
    {
        $hasAssessment = $this->assessments->hasAssessment($model->id);
        $findings = $this->assessments->getFindings($model->id);
        $riskScore = $this->assessments->getRiskScore($model->id);
        $carriesHighRiskObligations = $model->riskLevel->carriesHighRiskObligations();

        $breaches = [];

        if ($model->riskLevel->isProhibited()) {
            $breaches[] = sprintf(
                'it is classified "%s", which EU AI Act Article 5 prohibits outright',
                $model->riskLevel->value,
            );
        }

        if ($carriesHighRiskObligations && ! $hasAssessment) {
            $breaches[] = 'its tier carries the Article 9 risk-management duty and no impact '
                . 'assessment is on record';
        }

        if ($carriesHighRiskObligations && $model->card === null) {
            $breaches[] = 'its tier carries the Article 11 and Annex IV documentation duty and no '
                . 'model card is attached';
        }

        if ($hasAssessment && $riskScore > $this->impactRiskThreshold) {
            $breaches[] = sprintf(
                'its assessed impact risk has reached %.2f against a threshold of %.2f',
                $riskScore,
                $this->impactRiskThreshold,
            );
        }

        return new MonitoringResult(
            healthy: $breaches === [],
            hookName: self::NAME,
            message: $this->describe($model, $breaches),
            metrics: [
                'risk_level' => $model->riskLevel->value,
                'status' => $model->status->value,
                'in_service' => $model->status === AiModelStatus::Production,
                'carries_high_risk_obligations' => $carriesHighRiskObligations,
                'has_impact_assessment' => $hasAssessment,
                'has_model_card' => $model->card !== null,
                'impact_findings' => count($findings),
                'impact_risk_score' => $riskScore,
                'impact_risk_threshold' => $this->impactRiskThreshold,
                'breaches' => count($breaches),
            ],
        );
    }

    /**
     * @param list<string> $breaches
     *
     * @return non-empty-string
     */
    private function describe(AiModel $model, array $breaches): string
    {
        if ($breaches === []) {
            return sprintf(
                'The governance record of model "%s" (%s, %s) still satisfies the obligations it was '
                    . 'admitted under.',
                $model->id,
                $model->riskLevel->value,
                $model->status->value,
            );
        }

        return sprintf(
            'The governance record of model "%s" (%s, %s) no longer satisfies the obligations it was '
                . 'admitted under: %s.',
            $model->id,
            $model->riskLevel->value,
            $model->status->value,
            implode('; ', $breaches),
        );
    }
}
