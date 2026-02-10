<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when a saga completes all steps successfully.
 */
#[Api(since: '1.0.0')]
final readonly class SagaCompletedEvent
{
    public function __construct(
        public string $sagaId,
        public string $definitionId,
        public int $totalSteps,
        public DateTimeImmutable $occurredAt,
    ) {}
}
