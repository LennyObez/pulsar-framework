<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched by `workflow:check-timeouts` for each instance whose state
 * timeout deadline has passed.
 *
 * The framework detects the expiry and clears the deadline (so the event
 * fires exactly once per expiry); deciding what the timeout MEANS -- apply an
 * escalation transition, fail the instance, notify someone -- requires the
 * workflow definition, which the application owns. Listen to this event and
 * drive the engine with your definition to react.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class WorkflowTimedOutEvent
{
    public function __construct(
        public string $instanceId,
        public string $definitionId,
        public int $definitionVersion,
        public string $currentState,
        public DateTimeImmutable $timedOutAt,
        public DateTimeImmutable $occurredAt,
    ) {}
}
