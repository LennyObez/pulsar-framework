<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use Pulsar\Api\Api;

/**
 * Port for saga step execution result persistence.
 *
 * Forward execution rows are append-only. During compensation, new rows
 * are inserted with direction=compensating. Irreversible steps receive
 * status=skipped during compensation phases.
 */
#[Api(since: '1.0.0')]
interface SagaStepResultStorageInterface
{
    /**
     * Record a saga step result.
     */
    public function record(SagaStepResult $result): void;

    /**
     * Update the status and completion details of an existing step result.
     */
    public function updateStatus(
        string $id,
        SagaStepStatus $status,
        ?string $errorMessage = null,
    ): void;

    /**
     * Mark a step result as completed with optional result data.
     *
     * @param array<string, mixed>|null $resultData
     */
    public function markCompleted(
        string $id,
        ?array $resultData = null,
    ): void;

    /**
     * Get all step results for a saga instance, ordered by step index.
     *
     * @return list<SagaStepResult>
     */
    public function getByInstance(string $instanceId): array;

    /**
     * Get step results for a saga instance filtered by direction.
     *
     * @return list<SagaStepResult>
     */
    public function getByDirection(string $instanceId, SagaStepDirection $direction): array;

    /**
     * Find a step result by its idempotency key.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?SagaStepResult;

    /**
     * Increment the attempt counter for a step result.
     */
    public function incrementAttempts(string $id): void;
}
