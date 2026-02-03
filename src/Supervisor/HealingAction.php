<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Immutable evidence record of a self-healing action performed by the supervisor.
 *
 * Each healing action is assigned a unique identifier and optionally
 * correlated with related events via a correlation ID.
 */
#[Api]
final readonly class HealingAction
{
    public function __construct(
        public string $id,
        public HealingActionType $type,
        public string $description,
        public int $performedAt,
        public bool $success,
        public ?string $correlationId = null,
    ) {}
}
