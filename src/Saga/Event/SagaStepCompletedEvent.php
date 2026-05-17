<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when a saga step completes successfully.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SagaStepCompletedEvent
{
    /**
     * @param array<string, mixed> $output
     */
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public int $stepIndex,
        public array $output,
        public DateTimeImmutable $occurredAt,
    ) {}
}
