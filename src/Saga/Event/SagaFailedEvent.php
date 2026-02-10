<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when a saga fails (after compensation completes or itself fails).
 */
#[Api(since: '1.0.0')]
final readonly class SagaFailedEvent
{
    public function __construct(
        public string $sagaId,
        public string $definitionId,
        public string $failedStepName,
        public string $errorMessage,
        public bool $compensationSuccessful,
        public DateTimeImmutable $occurredAt,
    ) {}
}
