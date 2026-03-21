<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

/**
 * Registry for AI models under governance.
 *
 * ISO 42001:2023 Clause 8.2 requires organizations to identify, document,
 * and track all AI systems within scope. This registry provides the central
 * catalog of registered models with lifecycle and risk tracking.
 * @api
 */
#[Api(since: '1.0.0')]
interface AiModelRegistryInterface
{
    /**
     * Register a new AI model in the governance registry.
     */
    public function register(AiModel $model): void;

    /**
     * Retrieve a model by its unique identifier.
     */
    #[NoDiscard]
    public function get(string $modelId): ?AiModel;

    /**
     * Transition a model to a new lifecycle status.
     *
     * @throws InvalidArgumentException If the model is not found or the transition is invalid
     */
    public function transitionStatus(string $modelId, AiModelStatus $newStatus): AiModel;

    /**
     * Update the risk level classification of a model.
     *
     * @throws InvalidArgumentException If the model is not found
     */
    public function updateRiskLevel(string $modelId, AiModelRiskLevel $riskLevel): AiModel;

    /**
     * Return all registered models.
     *
     * @return array<string, AiModel> Keyed by model ID
     */
    #[NoDiscard]
    public function all(): array;

    /**
     * Return models filtered by lifecycle status.
     *
     * @return list<AiModel>
     */
    #[NoDiscard]
    public function byStatus(AiModelStatus $status): array;

    /**
     * Return models filtered by risk level.
     *
     * @return list<AiModel>
     */
    #[NoDiscard]
    public function byRiskLevel(AiModelRiskLevel $riskLevel): array;
}
