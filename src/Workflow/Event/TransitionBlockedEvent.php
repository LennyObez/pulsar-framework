<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;

/**
 * Dispatched when a transition is blocked by one or more guards.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TransitionBlockedEvent
{
    /**
     * @param list<string> $guardReasons Reasons from each guard that denied the transition
     */
    public function __construct(
        public string $instanceId,
        public string $definitionId,
        public string $transitionName,
        public string $fromState,
        public ActorContext $actor,
        public array $guardReasons,
        public DateTimeImmutable $occurredAt,
    ) {}
}
