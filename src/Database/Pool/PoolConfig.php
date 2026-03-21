<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for database connection pooling.
 *
 * Connection pooling is intended for persistent runtimes only (e.g., Swoole,
 * RoadRunner). Under traditional FPM, no pool is created.
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

    /**
     * Build from a raw config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int|string $minConnections */
        $minConnections = $data['min_connections'] ?? 2;

        /** @var int|string $maxConnections */
        $maxConnections = $data['max_connections'] ?? 10;

        /** @var int|string $idleTimeout */
        $idleTimeout = $data['idle_timeout_seconds'] ?? 60;

        /** @var int|string $maxLifetime */
        $maxLifetime = $data['max_lifetime_seconds'] ?? 3600;

        /** @var int|string $healthCheckInterval */
        $healthCheckInterval = $data['health_check_interval_seconds'] ?? 30;

        return new self(
            minConnections: (int) $minConnections,
            maxConnections: (int) $maxConnections,
            idleTimeoutSeconds: (int) $idleTimeout,
            maxLifetimeSeconds: (int) $maxLifetime,
            healthCheckIntervalSeconds: (int) $healthCheckInterval,
        );
    }
}
