<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;

/**
 * Configuration for database connection pooling.
 *
 * Connection pooling is intended for persistent runtimes only (e.g., Swoole,
 * RoadRunner). Under traditional FPM, no pool is created.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PoolConfig implements ReportsUnknownKeys
{
    /** Keys read from the `pool` sub-array of config/database.php. */
    private const array KNOWN_KEYS = [
        'min_connections', 'max_connections', 'idle_timeout_seconds',
        'max_lifetime_seconds', 'health_check_interval_seconds',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `pool` array that this
     *     DTO does not read — a misspelled `max_connections` caps the pool at 10
     *     regardless of the number the operator sized it to.
     */
    public function __construct(
        public int $minConnections = 2,
        public int $maxConnections = 10,
        public int $idleTimeoutSeconds = 60,
        public int $maxLifetimeSeconds = 3600,
        public int $healthCheckIntervalSeconds = 30,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * Build from a raw config array.
     *
     * @param array{
     *     min_connections?: int|string,
     *     max_connections?: int|string,
     *     idle_timeout_seconds?: int|string,
     *     max_lifetime_seconds?: int|string,
     *     health_check_interval_seconds?: int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            minConnections: (int) ($data['min_connections'] ?? 2),
            maxConnections: (int) ($data['max_connections'] ?? 10),
            idleTimeoutSeconds: (int) ($data['idle_timeout_seconds'] ?? 60),
            maxLifetimeSeconds: (int) ($data['max_lifetime_seconds'] ?? 3600),
            healthCheckIntervalSeconds: (int) ($data['health_check_interval_seconds'] ?? 30),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
