<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\PdoConnection;

use function array_pop;
use function count;
use function time;

/**
 * Connection pool for persistent runtimes (Swoole, RoadRunner, etc.).
 *
 * Manages a set of reusable database connections, enforcing limits on
 * pool size, idle timeouts, maximum connection lifetime, and periodic
 * health checks.
 */
#[Api(since: '1.0.0')]
final class ConnectionPool implements ConnectionPoolInterface
{
    /** @var list<PooledEntry> */
    private array $idle = [];

    private int $activeCount = 0;
    private int $totalCreated = 0;
    private int $totalDestroyed = 0;
    private int $waitCount = 0;

    public function __construct(
        private readonly PoolConfig $config,
        private readonly ConnectionConfig $connectionConfig,
    ) {}

    #[Override]
    public function checkout(): ConnectionInterface
    {
        $this->pruneExpired();

        while ($this->idle !== []) {
            $entry = array_pop($this->idle);

            if ($this->isExpired($entry)) {
                $this->destroyEntry($entry);

                continue;
            }

            if ($this->needsHealthCheck($entry)) {
                if (! $this->isHealthy($entry->connection)) {
                    $this->destroyEntry($entry);

                    continue;
                }

                $entry = new PooledEntry(
                    connection: $entry->connection,
                    createdAt: $entry->createdAt,
                    lastUsedAt: time(),
                );
            }

            $this->activeCount++;

            return new PooledConnection($entry->connection, $this, $entry->createdAt);
        }

        if ($this->activeCount >= $this->config->maxConnections) {
            throw DatabaseException::poolExhausted($this->config->maxConnections);
        }

        $connection = $this->createConnection();
        $this->activeCount++;

        return new PooledConnection($connection, $this, time());
    }

    #[Override]
    public function checkin(ConnectionInterface $connection): void
    {
        $this->activeCount = $this->activeCount > 0 ? $this->activeCount - 1 : 0;

        $createdAt = time();

        if ($connection instanceof PooledConnection) {
            $createdAt = $connection->createdAt();
            $connection = $connection->unwrap();
        }

        $entry = new PooledEntry(
            connection: $connection,
            createdAt: $createdAt,
            lastUsedAt: time(),
        );

        if ($this->isExpired($entry)) {
            $this->destroyEntry($entry);

            return;
        }

        if ($this->needsHealthCheck($entry)) {
            if (! $this->isHealthy($connection)) {
                $this->destroyEntry($entry);

                return;
            }
        }

        $this->idle[] = $entry;
    }

    #[Override]
    public function drain(): void
    {
        foreach ($this->idle as $entry) {
            $this->destroyEntry($entry);
        }

        $this->idle = [];
        $this->activeCount = 0;
    }

    #[Override]
    public function stats(): PoolStats
    {
        return new PoolStats(
            activeCount: $this->activeCount,
            idleCount: count($this->idle),
            totalCreated: $this->totalCreated,
            totalDestroyed: $this->totalDestroyed,
            waitCount: $this->waitCount,
        );
    }

    private function createConnection(): ConnectionInterface
    {
        $this->totalCreated++;

        return PdoConnection::fromConfig($this->connectionConfig);
    }

    private function destroyEntry(PooledEntry $entry): void
    {
        $entry->connection->disconnect();
        $this->totalDestroyed++;
    }

    private function isExpired(PooledEntry $entry): bool
    {
        $now = time();

        if (($now - $entry->createdAt) >= $this->config->maxLifetimeSeconds) {
            return true;
        }

        return ($now - $entry->lastUsedAt) >= $this->config->idleTimeoutSeconds;
    }

    private function needsHealthCheck(PooledEntry $entry): bool
    {
        return (time() - $entry->lastUsedAt) >= $this->config->healthCheckIntervalSeconds;
    }

    private function isHealthy(ConnectionInterface $connection): bool
    {
        try {
            $connection->query('SELECT 1');

            return true;
        } catch (DatabaseException) {
            return false;
        }
    }

    private function pruneExpired(): void
    {
        $kept = [];

        foreach ($this->idle as $entry) {
            if ($this->isExpired($entry)) {
                $this->destroyEntry($entry);
            } else {
                $kept[] = $entry;
            }
        }

        $this->idle = $kept;
    }
}
