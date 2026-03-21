<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Readonly DTO representing a persisted workflow instance.
 *
 * Maps to the `workflow_instances` table schema. The version field
 * supports optimistic locking via compare-and-swap semantics.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WorkflowInstance
{
    public function __construct(
        public string $id,
        public string $definitionId,
        public int $definitionVersion,
        public string $currentState,
        public ClassifiedContext $context,
        public int $version,
        public WorkflowInstanceStatus $status,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public string $startedBy,
        public ?DateTimeImmutable $timeoutAt = null,
    ) {}

    /**
     * Create a new instance with an updated state and incremented version.
     */
    #[NoDiscard]
    public function withState(string $newState, int $newVersion): self
    {
        return clone($this, [
            'currentState' => $newState,
            'version' => $newVersion,
        ]);
    }

    /**
     * Create a new instance with an updated status.
     */
    #[NoDiscard]
    public function withStatus(WorkflowInstanceStatus $status): self
    {
        return clone($this, [
            'status' => $status,
        ]);
    }

    /**
     * Create a new instance marked as completed.
     */
    #[NoDiscard]
    public function withCompleted(DateTimeImmutable $completedAt): self
    {
        return clone($this, [
            'status' => WorkflowInstanceStatus::Completed,
            'completedAt' => $completedAt,
        ]);
    }

    /**
     * Create a new instance with a timeout deadline.
     */
    #[NoDiscard]
    public function withTimeout(?DateTimeImmutable $timeoutAt): self
    {
        return clone($this, [
            'timeoutAt' => $timeoutAt,
        ]);
    }
}
