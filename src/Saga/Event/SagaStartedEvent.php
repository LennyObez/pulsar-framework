<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when a saga execution begins.
 */
#[Api(since: '1.0.0')]
final readonly class SagaStartedEvent
{
    public function __construct(
        public string $sagaId,
        public string $definitionId,
        public int $definitionVersion,
        public int $totalSteps,
        public DateTimeImmutable $occurredAt,
    ) {}
}
