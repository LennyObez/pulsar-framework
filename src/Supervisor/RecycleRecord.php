<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Immutable evidence record of a worker recycle event.
 *
 * Captures the reason, chosen action, and worker state at the time
 * the recycle decision was made.
 */
#[Api]
final readonly class RecycleRecord
{
    public function __construct(
        public RecycleReason $reason,
        public RecycleAction $action,
        public int $memoryUsageMb,
        public int $requestCount,
        public int $uptimeSeconds,
        public int $performedAt,
    ) {}
}
