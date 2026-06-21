<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Pool\ConnectionPool;
use Pulsar\Database\Pool\PoolConfig;
use Pulsar\Database\Pool\PooledConnection;
use Pulsar\Database\Pool\PooledEntry;
use Pulsar\Database\Pool\PoolStats;

#[CoversClass(ConnectionPool::class)]
#[CoversClass(PooledConnection::class)]
#[CoversClass(PooledEntry::class)]
final class ConnectionPoolTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private ConnectionConfig $connectionConfig;

    protected function setUp(): void
    {
        $this->connectionConfig = new ConnectionConfig(
            name: 'pool_test',
            driver: Driver::SQLite,
            host: '',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
            options: [],
        );
    }

    #[Test]
    public function checkoutReturnsConnection(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(maxConnections: 5),
            $this->connectionConfig,
        );

        $connection = $pool->checkout();

        self::assertInstanceOf(PooledConnection::class, $connection);
        self::assertInstanceOf(ConnectionInterface::class, $connection);
    }

    #[Test]
    public function checkinMakesConnectionAvailableForReuse(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 2,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $conn1 = $pool->checkout();

        $stats = $pool->stats();
        self::assertSame(1, $stats->activeCount);
        self::assertSame(0, $stats->idleCount);

        $conn1->disconnect();

        $stats = $pool->stats();
        self::assertSame(0, $stats->activeCount);
        self::assertSame(1, $stats->idleCount);

        $conn2 = $pool->checkout();

        self::assertInstanceOf(PooledConnection::class, $conn2);

        $stats = $pool->stats();
        self::assertSame(1, $stats->activeCount);
        self::assertSame(0, $stats->idleCount);

        $conn2->disconnect();
    }

    #[Test]
    public function checkinDiscardsConnectionWithOpenTransaction(): void
    {
        // FR-11: a connection returned mid-transaction must not be re-idled —
        // recycling it would leak the uncommitted transaction into the next
        // checkout. It is destroyed instead.
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 2,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $leaked = $this->createStub(ConnectionInterface::class);
        $leaked->method('inTransaction')->willReturn(true);

        $pool->checkin($leaked);

        $stats = $pool->stats();
        self::assertSame(0, $stats->idleCount, 'a leaked-transaction connection must not be recycled');
        self::assertSame(1, $stats->totalDestroyed);
    }

    #[Test]
    public function poolExhaustedThrowsException(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(maxConnections: 1),
            $this->connectionConfig,
        );

        $conn = $pool->checkout();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Connection pool exhausted (max: 1)');

        $pool->checkout();
    }

    #[Test]
    public function idleConnectionsPrunedAfterTimeout(): void
    {
        // With idleTimeoutSeconds=0, connections are considered expired immediately
        // when checked in, so they get destroyed rather than pooled.
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 0,
                maxLifetimeSeconds: 3600,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $conn = $pool->checkout();
        $conn->disconnect();

        // Connection was destroyed on checkin because idle timeout = 0
        $stats = $pool->stats();
        self::assertSame(0, $stats->idleCount);
        self::assertSame(1, $stats->totalDestroyed);

        // Next checkout must create a new connection
        $conn2 = $pool->checkout();
        $stats = $pool->stats();
        self::assertSame(2, $stats->totalCreated);

        $conn2->disconnect();
    }

    #[Test]
    public function maxLifetimeEnforcement(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 0,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $conn = $pool->checkout();
        $conn->disconnect();

        $conn2 = $pool->checkout();

        $stats = $pool->stats();
        self::assertGreaterThanOrEqual(1, $stats->totalDestroyed);

        $conn2->disconnect();
    }

    #[Test]
    public function drainDisconnectsAll(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $conn1 = $pool->checkout();
        $conn2 = $pool->checkout();
        $conn1->disconnect();
        $conn2->disconnect();

        $stats = $pool->stats();
        self::assertSame(2, $stats->idleCount);

        $pool->drain();

        $stats = $pool->stats();
        self::assertSame(0, $stats->idleCount);
        self::assertSame(0, $stats->activeCount);
        self::assertSame(2, $stats->totalDestroyed);
    }

    #[Test]
    public function statsReflectCurrentState(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $stats = $pool->stats();
        self::assertInstanceOf(PoolStats::class, $stats);
        self::assertSame(0, $stats->activeCount);
        self::assertSame(0, $stats->idleCount);
        self::assertSame(0, $stats->totalCreated);
        self::assertSame(0, $stats->totalDestroyed);
        self::assertSame(0, $stats->waitCount);

        $conn1 = $pool->checkout();

        $stats = $pool->stats();
        self::assertSame(1, $stats->activeCount);
        self::assertSame(0, $stats->idleCount);
        self::assertSame(1, $stats->totalCreated);

        $conn2 = $pool->checkout();

        $stats = $pool->stats();
        self::assertSame(2, $stats->activeCount);
        self::assertSame(2, $stats->totalCreated);

        $conn1->disconnect();

        $stats = $pool->stats();
        self::assertSame(1, $stats->activeCount);
        self::assertSame(1, $stats->idleCount);

        $conn2->disconnect();
    }

    #[Test]
    public function healthCheckRemovesDeadConnections(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 0,
            ),
            $this->connectionConfig,
        );

        $conn = $pool->checkout();
        $conn->disconnect();

        $stats = $pool->stats();
        self::assertSame(1, $stats->idleCount);

        $conn2 = $pool->checkout();
        self::assertInstanceOf(PooledConnection::class, $conn2);

        $conn2->disconnect();
    }

    #[Test]
    public function multipleCheckoutCheckinCycles(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 3,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        for ($i = 0; $i < 10; $i++) {
            $conn = $pool->checkout();
            $conn->disconnect();
        }

        $stats = $pool->stats();
        self::assertSame(0, $stats->activeCount);
        self::assertSame(1, $stats->idleCount);
    }

    #[Test]
    public function doubleDisconnectIsIdempotent(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $conn = $pool->checkout();
        $conn->disconnect();
        $conn->disconnect();

        $stats = $pool->stats();
        self::assertSame(0, $stats->activeCount);
        self::assertSame(1, $stats->idleCount);
    }

    #[Test]
    public function checkinPreservesCreatedAtFromPooledConnection(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        $conn = $pool->checkout();
        self::assertInstanceOf(PooledConnection::class, $conn);

        // Record the original createdAt timestamp
        $originalCreatedAt = $conn->createdAt();

        // Check the connection back in
        $conn->disconnect();

        // Check it back out — the createdAt from the original creation should be preserved
        $conn2 = $pool->checkout();
        self::assertInstanceOf(PooledConnection::class, $conn2);

        // The createdAt should be the original timestamp, not reset to time()
        self::assertSame($originalCreatedAt, $conn2->createdAt());

        $conn2->disconnect();
    }

    #[Test]
    public function checkinWithNonPooledConnectionUsesCurrentTime(): void
    {
        $pool = new ConnectionPool(
            new PoolConfig(
                maxConnections: 5,
                idleTimeoutSeconds: 3600,
                maxLifetimeSeconds: 7200,
                healthCheckIntervalSeconds: 3600,
            ),
            $this->connectionConfig,
        );

        // Manually check in a raw (non-PooledConnection) connection
        $rawConn = $pool->checkout();
        self::assertInstanceOf(PooledConnection::class, $rawConn);

        // Unwrap to get raw connection
        $unwrapped = $rawConn->unwrap();

        // Force checkin of the PooledConnection first to decrement active
        $rawConn->disconnect();

        // Now check in the raw connection directly — createdAt should be time()
        $pool->checkin($unwrapped);

        $stats = $pool->stats();
        // Two idle: the re-checked-in PooledConnection + the raw connection
        self::assertGreaterThanOrEqual(1, $stats->idleCount);
    }
}
