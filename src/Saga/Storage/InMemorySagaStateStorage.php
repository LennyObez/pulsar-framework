<?php

declare(strict_types=1);

namespace Pulsar\Saga\Storage;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStateStorageInterface;

/**
 * Process-local {@see SagaStateStorageInterface}.
 *
 * Keeps saga state in an in-memory map keyed by saga id. This is the default
 * binding so the saga engine is runnable out of the box (tests, single-process
 * orchestration); it is NOT durable across processes — a database-backed
 * implementation must be wired for executions that need to survive restarts.
 */
#[Internal]
final class InMemorySagaStateStorage implements SagaStateStorageInterface
{
    /** @var array<string, SagaState> */
    private array $states = [];

    #[Override]
    public function save(SagaState $state): void
    {
        $this->states[$state->sagaId] = $state;
    }

    #[Override]
    public function findById(string $sagaId): ?SagaState
    {
        return $this->states[$sagaId] ?? null;
    }
}
