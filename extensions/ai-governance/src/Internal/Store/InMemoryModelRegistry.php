<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function array_filter;
use function array_values;
use function in_array;

/**
 * In-memory implementation of the AI model registry for development and testing.
 *
 * Holds one invariant beyond the status state machine: no model classified as
 * an EU AI Act Article 5 prohibited practice is ever in production status.
 * `AiLifecycleManager` refuses such a deployment at its gates, but
 * `transitionStatus()` and `register()` are public contract surface a caller can
 * reach without going through the lifecycle manager at all, so the invariant is
 * held here as well as gated there.
 */
#[Internal(reason: 'Development store; production deployments should use a persistent implementation')]
final class InMemoryModelRegistry implements AiModelRegistryInterface
{
    /** @var array<string, AiModel> */
    private array $models = [];

    /** @var array<string, AiModelStatus> Tracks the previous status for rollback */
    private array $previousStatuses = [];

    private const array VALID_TRANSITIONS = [
        'development' => ['testing', 'retired'],
        'testing' => ['staging', 'development', 'retired'],
        'staging' => ['production', 'testing', 'retired'],
        'production' => ['deprecated', 'staging'],
        'deprecated' => ['retired', 'production'],
        'retired' => [],
    ];

    #[Override]
    public function register(AiModel $model): void
    {
        if ($model->status === AiModelStatus::Production && $model->riskLevel->isProhibited()) {
            throw AiGovernanceException::prohibitedPractice($model->id);
        }

        $this->models[$model->id] = $model;
    }

    #[Override]
    public function get(string $modelId): ?AiModel
    {
        return $this->models[$modelId] ?? null;
    }

    #[Override]
    public function transitionStatus(string $modelId, AiModelStatus $newStatus): AiModel
    {
        $model = $this->models[$modelId] ?? null;

        if ($model === null) {
            throw AiGovernanceException::modelNotFound($modelId);
        }

        // Checked before the transition table, so a prohibited model is refused
        // for the reason that actually governs it rather than for the shape of
        // the state machine it happened to violate on the way.
        if ($newStatus === AiModelStatus::Production && $model->riskLevel->isProhibited()) {
            throw AiGovernanceException::prohibitedPractice($modelId);
        }

        $allowedTransitions = self::VALID_TRANSITIONS[$model->status->value] ?? [];

        /** @var string $candidate */
        $candidate = $newStatus->value;

        if (! in_array($candidate, $allowedTransitions, true)) {
            throw AiGovernanceException::invalidStatusTransition(
                $modelId,
                $model->status->value,
                $newStatus->value,
            );
        }

        $this->previousStatuses[$modelId] = $model->status;
        $updated = $model->withStatus($newStatus);
        $this->models[$modelId] = $updated;

        return $updated;
    }

    /**
     * Repeats the interface's `#[NoDiscard]` because PHP does not inherit the
     * attribute: this method can withdraw a live model from production, and a
     * caller that drops the return value is the caller that never finds out.
     */
    #[Override]
    #[NoDiscard]
    public function updateRiskLevel(string $modelId, AiModelRiskLevel $riskLevel): AiModel
    {
        $model = $this->models[$modelId] ?? null;

        if ($model === null) {
            throw AiGovernanceException::modelNotFound($modelId);
        }

        $updated = $model->withRiskLevel($riskLevel);

        // Reclassifying a live model as an Article 5 prohibited practice is the
        // one case where recording the truth and holding the invariant pull
        // against each other. Refusing the update would leave a prohibited
        // system in production *and* unrecorded, which is worse than either, so
        // the classification is accepted and the model is withdrawn from
        // production in the same operation. Deprecated rather than Retired: a
        // classification can be corrected, and Retired is terminal.
        if ($riskLevel->isProhibited() && $model->status === AiModelStatus::Production) {
            $this->previousStatuses[$modelId] = $model->status;
            $updated = $updated->withStatus(AiModelStatus::Deprecated);
        }

        $this->models[$modelId] = $updated;

        return $updated;
    }

    #[Override]
    public function all(): array
    {
        return $this->models;
    }

    #[Override]
    public function byStatus(AiModelStatus $status): array
    {
        return array_values(array_filter(
            $this->models,
            static fn(AiModel $model): bool => $model->status === $status,
        ));
    }

    #[Override]
    public function byRiskLevel(AiModelRiskLevel $riskLevel): array
    {
        return array_values(array_filter(
            $this->models,
            static fn(AiModel $model): bool => $model->riskLevel === $riskLevel,
        ));
    }

    /**
     * Get the previous status of a model (used for rollback).
     */
    public function getPreviousStatus(string $modelId): ?AiModelStatus
    {
        return $this->previousStatuses[$modelId] ?? null;
    }
}
