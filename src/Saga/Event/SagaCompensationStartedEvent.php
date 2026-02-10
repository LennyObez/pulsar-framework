<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when saga compensation begins after a step failure.
 */
#[Api(since: '1.0.0')]
final readonly class SagaCompensationStartedEvent
{
    public function __construct(
        public string $sagaId,
        public string $failedStepName,
        public int $stepsToCompensate,
        public DateTimeImmutable $occurredAt,
    ) {}
}
