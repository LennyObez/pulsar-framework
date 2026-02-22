<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when a saga step fails after exhausting retries.
 */
#[Api(since: '1.0.0')]
final readonly class SagaStepFailedEvent
{
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public int $stepIndex,
        public string $errorMessage,
        public DateTimeImmutable $occurredAt,
    ) {}
}
