<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Gate;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;

/**
 * Deployment gate requiring an attached model card.
 *
 * ISO 42001:2023 Clause 8.2 requires AI systems to be documented; a model
 * card captures capabilities, limitations, and known biases. Wired when
 * AiGovernanceConfig::$requireModelCard is enabled.
 *
 * IT DOES NOT CONSULT THE ACTOR ROLE, deliberately. The EU AI Act obligation a
 * model card stands in for — Article 11 and Annex IV technical documentation — is
 * a provider duty, and it is enforced against providers by
 * {@see HighRiskObligationsGate}, which does consult the role. This gate is a
 * different thing: an ISO 42001 Clause 8.2 documentation requirement an operator
 * chooses to impose on EVERY model in its management system, whatever its tier
 * and whatever role the deployment holds. Making a house rule conditional on a
 * regulatory role would let a deployment escape its own policy by declaring
 * itself a deployer.
 */
#[Internal]
final class ModelCardGate implements DeploymentGateInterface
{
    #[Override]
    public function name(): string
    {
        return 'model_card';
    }

    #[Override]
    public function evaluate(AiModel $model): bool
    {
        return $model->card !== null;
    }

    #[Override]
    public function failureReason(): string
    {
        return 'A model card must be attached before deployment (ISO 42001:2023 Clause 8.2).';
    }
}
