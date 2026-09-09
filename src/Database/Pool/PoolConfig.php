<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Pulsar\Api\Api;

/**
 * Sizing and lifetime limits for a {@see ConnectionPool}.
 *
 * Read by the pool and by nothing else. It is deliberately NOT parsed out of
 * `config/database.php`: that file carried a `pool` section until 1.0.0-rc.12 which
 * was parsed into this object and then dropped on the floor, because no wiring ever
 * built a pool from it. An operator who raised `max_connections` there changed
 * nothing at all.
 *
 * Pooling is for persistent runtimes (Swoole, RoadRunner) where one process serves
 * many requests; under FPM a process serves one request and a pool has nothing to
 * amortise. An application that wants one constructs it in its own composition root:
 *
 * ```php
 * $pool = new ConnectionPool(
 *     new PoolConfig(minConnections: 2, maxConnections: 10),
 *     $databaseConfig->connection('mysql'),
 *     $logger,
 * );
 * ```
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PoolConfig
{
    public function __construct(
        public int $minConnections = 2,
        public int $maxConnections = 10,
        public int $idleTimeoutSeconds = 60,
        public int $maxLifetimeSeconds = 3600,
        public int $healthCheckIntervalSeconds = 30,
    ) {}
}
