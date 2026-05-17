<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;

/**
 * Dispatched when a workflow instance reaches a final state.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WorkflowCompletedEvent
{
    public function __construct(
        public string $instanceId,
        public string $definitionId,
        public string $finalState,
        public ActorContext $actor,
        public DateTimeImmutable $occurredAt,
    ) {}
}
