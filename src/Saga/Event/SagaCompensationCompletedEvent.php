<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when all compensation actions complete successfully.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SagaCompensationCompletedEvent
{
    public function __construct(
        public string $sagaId,
        public int $stepsCompensated,
        public DateTimeImmutable $occurredAt,
    ) {}
}
