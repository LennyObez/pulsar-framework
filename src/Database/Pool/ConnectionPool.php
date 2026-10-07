<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Override;
use Psr\Log\LoggerInterface;
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
 * @api
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
        private readonly ?LoggerInterface $logger = null,
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
            $this->waitCount++;

            $this->logger?->warning('Connection pool exhausted', [
                'max_connections' => $this->config->maxConnections,
                'active_count' => $this->activeCount,
                'wait_count' => $this->waitCount,
            ]);

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

        if ($connection instanceof PooledConnection) {
            $createdAt = $connection->createdAt();
            $connection = $connection->unwrap();
        } else {
            $createdAt = time();
        }

        $entry = new PooledEntry(
            connection: $connection,
            createdAt: $createdAt,
            lastUsedAt: time(),
        );

        // A connection returned while a transaction is still open carries
        // uncommitted, caller-private state. Recycling it would leak that
        // transaction into the next checkout (cross-request bleed in persistent
        // runtimes). The pool cannot guarantee a clean reset through
        // ConnectionInterface — a bare ROLLBACK would not clear session temp
        // tables or variables and would desynchronize the connection's own
        // transaction-depth tracking — so the connection is destroyed (its
        // teardown rolls the transaction back) rather than re-idled. The leak
        // signals a caller bug and is logged.
        if ($connection->inTransaction()) {
            $this->logger?->warning('Connection returned to pool with an open transaction; discarding', [
                'created_at' => $createdAt,
            ]);

            $this->destroyEntry($entry);

            return;
        }

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

    /**
     * Pre-create connections up to the configured minimum pool size.
     *
     * Call during application bootstrap to avoid cold-start latency
     * on the first batch of requests.
     */
    public function warmUp(): void
    {
        $needed = $this->config->minConnections - count($this->idle) - $this->activeCount;

        for ($i = 0; $i < $needed; $i++) {
            try {
                $connection = $this->createConnection();

                $this->idle[] = new PooledEntry(
                    connection: $connection,
                    createdAt: time(),
                    lastUsedAt: time(),
                );
            } catch (DatabaseException $e) {
                $this->logger?->warning('Connection pool warmup failed for connection ' . $i, ['exception' => $e]);
            }
        }
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
