<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;

/**
 * Dispatched when a new workflow instance is started.
 */
#[Api(since: '1.0.0')]
final readonly class WorkflowStartedEvent
{
    public function __construct(
        public string $instanceId,
        public string $definitionId,
        public int $definitionVersion,
        public string $initialState,
        public ActorContext $actor,
        public DateTimeImmutable $occurredAt,
    ) {}
}
