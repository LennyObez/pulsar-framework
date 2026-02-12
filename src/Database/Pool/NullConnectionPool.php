<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\PdoConnection;

/**
 * No-op connection pool for FPM environments.
 *
 * Creates a fresh connection on every checkout and disconnects
 * immediately on checkin. No connection reuse occurs.
 */
#[Api(since: '1.0.0')]
final class NullConnectionPool implements ConnectionPoolInterface
{
    public function __construct(
        private readonly ConnectionConfig $connectionConfig,
    ) {}

    #[Override]
    public function checkout(): ConnectionInterface
    {
        return PdoConnection::fromConfig($this->connectionConfig);
    }

    #[Override]
    public function checkin(ConnectionInterface $connection): void
    {
        $connection->disconnect();
    }

    #[Override]
    public function drain(): void
    {
        // No-op: FPM connections are not pooled.
    }

    #[Override]
    public function stats(): PoolStats
    {
        return new PoolStats(
            activeCount: 0,
            idleCount: 0,
            totalCreated: 0,
            totalDestroyed: 0,
            waitCount: 0,
        );
    }
}
