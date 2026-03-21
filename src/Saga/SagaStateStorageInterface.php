<?php

declare(strict_types=1);

namespace Pulsar\Saga;

use Pulsar\Api\Api;

/**
 * Port for persisting saga execution state.
 *
 * Saga state is saved after each step to ensure durable execution
 * that survives process restarts.
 * @api
 */
#[Api(since: '1.0.0')]
interface SagaStateStorageInterface
{
    /**
     * Save the saga state (create or update).
     */
    public function save(SagaState $state): void;

    /**
     * Load the saga state by its ID.
     */
    public function findById(string $sagaId): ?SagaState;
}
