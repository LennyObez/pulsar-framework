<?php

declare(strict_types=1);

namespace Pulsar\Saga\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Typed compliance event emitted when a saga fails after irreversible steps.
 *
 * In regulated presets, this event requires operator acknowledgment. It
 * contains full details about which irreversible steps completed, which
 * step failed, and a hint about required operator action.
 */
#[Api(since: '1.0.0')]
final readonly class IrreversibleSagaFailureEvent
{
    /**
     * @param list<string> $completedIrreversibleSteps Step names of irreversible steps that completed
     */
    public function __construct(
        public string $sagaId,
        public string $definitionId,
        public array $completedIrreversibleSteps,
        public string $failedStepName,
        public string $errorMessage,
        public string $operatorActionHint,
        public DateTimeImmutable $occurredAt,
    ) {}
}
