<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Pool\NullConnectionPool;
use Pulsar\Database\Pool\PoolStats;

#[CoversClass(NullConnectionPool::class)]
final class NullConnectionPoolTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private NullConnectionPool $pool;

    protected function setUp(): void
    {
        $this->pool = new NullConnectionPool(
            new ConnectionConfig(
                name: 'null_pool_test',
                driver: Driver::SQLite,
                host: '',
                port: 0,
                database: ':memory:',
                username: '',
                password: '',
                charset: 'utf8mb4',
                collation: 'utf8mb4_unicode_ci',
                options: [],
            ),
        );
    }

    #[Test]
    public function checkoutCreatesFreshConnection(): void
    {
        $conn1 = $this->pool->checkout();
        $conn2 = $this->pool->checkout();

        self::assertInstanceOf(ConnectionInterface::class, $conn1);
        self::assertInstanceOf(ConnectionInterface::class, $conn2);
        self::assertNotSame($conn1, $conn2);

        $conn1->disconnect();
        $conn2->disconnect();
    }

    #[Test]
    public function checkinDisconnectsImmediately(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('disconnect');

        $this->pool->checkin($connection);
    }

    #[Test]
    public function drainIsNoOp(): void
    {
        $this->pool->drain();

        $stats = $this->pool->stats();
        self::assertSame(0, $stats->activeCount);
    }

    #[Test]
    public function statsReturnsZeros(): void
    {
        $stats = $this->pool->stats();

        self::assertInstanceOf(PoolStats::class, $stats);
        self::assertSame(0, $stats->activeCount);
        self::assertSame(0, $stats->idleCount);
        self::assertSame(0, $stats->totalCreated);
        self::assertSame(0, $stats->totalDestroyed);
        self::assertSame(0, $stats->waitCount);
    }
}
