<?php

declare(strict_types=1);

namespace Pulsar\Saga;

use Pulsar\Api\Api;
use Pulsar\Saga\Exception\SagaException;

/**
 * Public API for saga orchestration.
 *
 * Executes saga definitions step-by-step with durable state persistence,
 * automatic compensation on failure, and compliance event emission.
 */
#[Api(since: '1.0.0')]
interface SagaOrchestratorInterface
{
    /**
     * Execute a saga from the beginning.
     *
     * Runs all steps sequentially. On failure, compensates completed steps
     * in reverse order (skipping irreversible ones). State is persisted
     * after each step for durability.
     *
     * @param array<string, mixed> $context Initial saga context
     *
     * @throws SagaException On saga failure (after compensation attempt)
     */
    public function execute(SagaDefinition $definition, array $context): SagaState;

    /**
     * Resume a previously interrupted saga from its last persisted state.
     *
     * @throws SagaException If the saga cannot be found or is not resumable
     */
    public function resume(string $sagaId, SagaDefinition $definition): SagaState;

    /**
     * Force compensation of a running saga.
     *
     * @throws SagaException If the saga cannot be found or is not in a compensable state
     */
    public function compensate(string $sagaId, SagaDefinition $definition): SagaState;
}
