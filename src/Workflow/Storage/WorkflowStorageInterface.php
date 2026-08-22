<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Exception\ConcurrentTransitionException;

/**
 * Port for workflow instance persistence.
 *
 * Provides CRUD operations for workflow instances with optimistic
 * locking via compare-and-swap on state transitions.
 * @api
 */
#[Api(since: '1.0.0')]
interface WorkflowStorageInterface
{
    /**
     * Persist a new workflow instance.
     */
    public function create(WorkflowInstance $instance): void;

    /**
     * Find a workflow instance by its unique identifier.
     */
    public function findById(string $id): ?WorkflowInstance;

    /**
     * Update the current state of a workflow instance using CAS semantics.
     *
     * The update succeeds only if the stored version matches expectedVersion.
     * On success, the version is incremented atomically and the updated
     * instance is returned. On version mismatch, a ConcurrentTransitionException
     * is thrown: the caller may retry.
     *
     * @throws ConcurrentTransitionException If the stored version does not match expectedVersion.
     */
    public function updateState(
        string $id,
        string $newState,
        int $expectedVersion,
        ActorContext $actor,
        ?string $reason = null,
    ): WorkflowInstance;

    /**
     * Update the status of a workflow instance.
     */
    public function updateStatus(string $id, WorkflowInstanceStatus $status): void;

    /**
     * Find all instances for a given workflow definition.
     *
     * @return list<WorkflowInstance>
     */
    public function findByDefinition(string $definitionId): array;

    /**
     * Find all instances with a given status.
     *
     * @return list<WorkflowInstance>
     */
    public function findByStatus(WorkflowInstanceStatus $status): array;

    /**
     * Update the timeout deadline for a workflow instance.
     *
     * Set to a DateTimeImmutable to schedule a timeout, or null to cancel it.
     */
    public function updateTimeout(string $id, ?DateTimeImmutable $timeoutAt): void;

    /**
     * Find active instances whose timeout deadline has passed.
     *
     * Returns instances where timeout_at is not null and is before
     * the current time, and status is Active. Results are ordered
     * by timeout_at ascending (oldest first).
     *
     * @param positive-int $limit Maximum number of instances to return (prevents unbounded result sets)
     *
     * @return list<WorkflowInstance>
     */
    public function findExpiredTimeouts(int $limit = 100): array;
}
