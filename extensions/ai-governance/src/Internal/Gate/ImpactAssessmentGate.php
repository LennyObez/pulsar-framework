<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Gate;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;

use function sprintf;

/**
 * Deployment gate requiring a passed impact assessment.
 *
 * ISO 42001:2023 Clause 6.1.2 requires impact assessment before deployment.
 * "Passing" is defined as: an assessment has been performed for the model
 * AND the resulting overall risk score does not exceed the configured
 * threshold. A never-assessed model fails (distinct from "assessed with no
 * adverse findings", which passes with a 0.0 score). Wired when
 * AiGovernanceConfig::$requireImpactAssessment is enabled.
 */
#[Internal]
final readonly class ImpactAssessmentGate implements DeploymentGateInterface
{
    public function __construct(
        private AiImpactAssessmentInterface $assessments,
        private float $riskThreshold,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'impact_assessment';
    }

    #[Override]
    public function evaluate(AiModel $model): bool
    {
        if (! $this->assessments->hasAssessment($model->id)) {
            return false;
        }

        return $this->assessments->getRiskScore($model->id) <= $this->riskThreshold;
    }

    #[Override]
    public function failureReason(): string
    {
        return sprintf(
            'A completed impact assessment with an overall risk score at or below %.1f is required before deployment (ISO 42001:2023 Clause 6.1.2).',
            $this->riskThreshold,
        );
    }
}
