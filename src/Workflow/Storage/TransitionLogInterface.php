<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Append-only transition log port.
 *
 * Records every state transition for a workflow instance as an immutable
 * log entry. Supports full history retrieval and state reconstruction
 * from the transition log alone.
 * @api
 */
#[Api(since: '1.0.0')]
interface TransitionLogInterface
{
    /**
     * Record a transition in the append-only log.
     *
     * Records are never updated or deleted after insertion.
     */
    public function record(TransitionRecord $record): void;

    /**
     * Get the complete transition history for a workflow instance.
     *
     * Returns records ordered by creation time ascending.
     *
     * @return list<TransitionRecord>
     */
    public function getHistory(string $instanceId): array;

    /**
     * Derive the current state of a workflow instance from its transition log.
     *
     * Returns the `to_state` of the most recent transition record.
     *
     * @throws RuntimeException If no transitions exist for the instance.
     */
    public function reconstructState(string $instanceId): string;
}
