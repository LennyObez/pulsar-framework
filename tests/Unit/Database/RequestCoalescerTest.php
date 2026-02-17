<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\RequestCoalescer;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(RequestCoalescer::class)]
final class RequestCoalescerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
    }

    #[Test]
    public function identicalQueriesAreCoalesced(): void
    {
        $result = new Result([new Row(['id' => 1, 'name' => 'Alice'])]);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection
            ->expects(self::once())
            ->method('query')
            ->with('SELECT * FROM users WHERE id = :id', ['id' => 42])
            ->willReturn($result);

        $coalescer = new RequestCoalescer($this->connection);

        $first = $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 42]);
        $second = $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 42]);

        self::assertSame($first, $second);
        self::assertSame(1, $coalescer->hits());
        self::assertSame(1, $coalescer->misses());
    }

    #[Test]
    public function differentBindingsAreNotCoalesced(): void
    {
        $result1 = new Result([new Row(['id' => 1])]);
        $result2 = new Result([new Row(['id' => 2])]);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection
            ->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($result1, $result2);

        $coalescer = new RequestCoalescer($this->connection);

        $first = $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
        $second = $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 2]);

        self::assertNotSame($first, $second);
        self::assertSame(0, $coalescer->hits());
        self::assertSame(2, $coalescer->misses());
    }

    #[Test]
    public function executeInvalidatesCacheAndDelegates(): void
    {
        $result = new Result([new Row(['id' => 1])]);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection
            ->expects(self::exactly(2))
            ->method('query')
            ->willReturn($result);
        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with('UPDATE users SET name = :name WHERE id = :id', ['name' => 'Bob', 'id' => 1])
            ->willReturn(1);

        $coalescer = new RequestCoalescer($this->connection);

        // First query populates cache
        $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

        // Execute invalidates all cached results
        $affected = $coalescer->execute('UPDATE users SET name = :name WHERE id = :id', ['name' => 'Bob', 'id' => 1]);
        self::assertSame(1, $affected);

        // Same query must hit the database again
        $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
        self::assertSame(0, $coalescer->hits());
    }

    #[Test]
    public function resetClearsCacheAndCounters(): void
    {
        $result = new Result([new Row(['id' => 1])]);
        $this->connection->method('query')->willReturn($result);

        $coalescer = new RequestCoalescer($this->connection);
        $coalescer->query('SELECT 1', []);
        $coalescer->query('SELECT 1', []);

        self::assertSame(1, $coalescer->hits());
        self::assertSame(1, $coalescer->size());

        $coalescer->reset();

        self::assertSame(0, $coalescer->hits());
        self::assertSame(0, $coalescer->misses());
        self::assertSame(0, $coalescer->size());
    }

    #[Test]
    public function hitRateCalculatesCorrectly(): void
    {
        $result = new Result([]);
        $this->connection->method('query')->willReturn($result);

        $coalescer = new RequestCoalescer($this->connection);

        // 0 total = 0%
        self::assertSame(0.0, $coalescer->hitRate());

        // 1 miss
        $coalescer->query('SELECT 1', []);
        self::assertSame(0.0, $coalescer->hitRate());

        // 1 hit
        $coalescer->query('SELECT 1', []);
        self::assertSame(50.0, $coalescer->hitRate());

        // 2 hits
        $coalescer->query('SELECT 1', []);
        self::assertEqualsWithDelta(66.67, $coalescer->hitRate(), 0.01);
    }

    #[Test]
    public function lruEvictionRemovesOldestEntries(): void
    {
        $result = new Result([]);
        $this->connection->method('query')->willReturn($result);

        $coalescer = new RequestCoalescer($this->connection, maxEntries: 2);

        $coalescer->query('SELECT 1', []);
        $coalescer->query('SELECT 2', []);
        self::assertSame(2, $coalescer->size());

        // Adding a third should evict the first
        $coalescer->query('SELECT 3', []);
        self::assertSame(2, $coalescer->size());
    }

    #[Test]
    public function invalidateRemovesSpecificQuery(): void
    {
        $result = new Result([new Row(['id' => 1])]);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection
            ->expects(self::exactly(2))
            ->method('query')
            ->willReturn($result);

        $coalescer = new RequestCoalescer($this->connection);

        $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
        self::assertSame(1, $coalescer->size());

        $coalescer->invalidate('SELECT * FROM users WHERE id = :id', ['id' => 1]);
        self::assertSame(0, $coalescer->size());

        // Must re-execute after invalidation
        $coalescer->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
        self::assertSame(0, $coalescer->hits());
    }

    #[Test]
    public function invalidateNonExistentKeyIsNoOp(): void
    {
        $coalescer = new RequestCoalescer($this->connection);

        // Should not throw
        $coalescer->invalidate('SELECT * FROM nonexistent', []);
        self::assertSame(0, $coalescer->size());
    }
}
