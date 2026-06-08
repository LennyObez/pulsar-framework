<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

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

        /** @var list<string> $allowedTransitions */
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

    #[Override]
    public function updateRiskLevel(string $modelId, AiModelRiskLevel $riskLevel): AiModel
    {
        $model = $this->models[$modelId] ?? null;

        if ($model === null) {
            throw AiGovernanceException::modelNotFound($modelId);
        }

        $updated = $model->withRiskLevel($riskLevel);
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
