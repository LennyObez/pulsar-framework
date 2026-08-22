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
