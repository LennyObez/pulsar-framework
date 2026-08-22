<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\AiModel;

/**
 * Pre-deployment validation gate for AI models.
 *
 * ISO 42001:2023 Clause 8.4 requires organizations to validate AI systems
 * before deployment. Each gate represents a specific validation check that
 * must pass before a model can transition to production.
 * @api
 */
#[Api(since: '1.0.0')]
interface DeploymentGateInterface
{
    /**
     * Get the unique name of this gate.
     */
    #[NoDiscard]
    public function name(): string;

    /**
     * Evaluate whether the model passes this gate.
     *
     * @return bool True if the model passes, false otherwise
     */
    #[NoDiscard]
    public function evaluate(AiModel $model): bool;

    /**
     * Get the reason for failure if evaluate() returned false.
     */
    #[NoDiscard]
    public function failureReason(): string;
}
