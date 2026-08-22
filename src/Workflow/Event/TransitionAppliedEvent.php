<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;

/**
 * Dispatched after a transition has been successfully applied.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TransitionAppliedEvent
{
    public function __construct(
        public string $instanceId,
        public string $definitionId,
        public string $transitionName,
        public string $fromState,
        public string $toState,
        public ActorContext $actor,
        public ?string $reason,
        public int $instanceVersion,
        public DateTimeImmutable $occurredAt,
    ) {}
}
