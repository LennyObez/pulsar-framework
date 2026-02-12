<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Pulsar\Api\Api;

/**
 * Snapshot of connection pool statistics.
 */
#[Api(since: '1.0.0')]
final readonly class PoolStats
{
    public function __construct(
        public int $activeCount,
        public int $idleCount,
        public int $totalCreated,
        public int $totalDestroyed,
        public int $waitCount,
    ) {}
}
