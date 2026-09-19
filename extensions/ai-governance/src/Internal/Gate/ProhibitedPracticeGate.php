<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Gate;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;

/**
 * Deployment gate refusing any model classified as an EU AI Act Article 5
 * prohibited practice.
 *
 * This gate is wired unconditionally, with no configuration key behind it. The
 * other gates in this extension express requirements an operator may choose to
 * impose; a prohibition is not one of those. It is also deliberately redundant
 * with the model registry, which refuses the transition into production on its
 * own: the registry holds the invariant, and this gate is what produces the
 * audit record — a `DeploymentGateFailed` event naming the article — before the
 * registry is ever reached.
 *
 * IT DOES NOT CONSULT THE ACTOR ROLE, and that is a finding rather than an
 * omission. Article 5 prohibits "the placing on the market, the putting into
 * service or the USE" of these systems, so it binds a provider and a deployer
 * alike; there is no role a deployment can declare that makes the practice
 * permitted. Passing a role into this gate would suggest one might.
 */
#[Internal]
final readonly class ProhibitedPracticeGate implements DeploymentGateInterface
{
    #[Override]
    public function name(): string
    {
        return 'prohibited_practice';
    }

    #[Override]
    public function evaluate(AiModel $model): bool
    {
        return ! $model->riskLevel->isProhibited();
    }

    #[Override]
    public function failureReason(): string
    {
        return 'The model is classified as an unacceptable-risk AI system: EU AI Act '
            . '(Regulation (EU) 2024/1689) Article 5 prohibits these practices outright, so it may '
            . 'not be deployed to production under any conditions. Reclassify the model or retire it.';
    }
}
