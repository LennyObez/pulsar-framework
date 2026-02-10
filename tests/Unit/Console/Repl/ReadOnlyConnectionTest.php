<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ReadOnlyConnection;
use Pulsar\Console\Repl\ReplSafeModeException;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Throwable;

#[CoversClass(ReadOnlyConnection::class)]
final class ReadOnlyConnectionTest extends TestCase
{
    private ConnectionInterface&Stub $inner;
    private ReadOnlyConnection $connection;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(ConnectionInterface::class);
        $this->connection = new ReadOnlyConnection($this->inner);
    }

    #[Test]
    #[DataProvider('allowedQueryProvider')]
    public function queryAllowsReadOnlyStatements(string $sql): void
    {
        $expectedResult = new Result([]);
        $this->inner->method('query')->willReturn($expectedResult);

        $result = $this->connection->query($sql);

        self::assertSame($expectedResult, $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedQueryProvider(): iterable
    {
        yield 'SELECT' => ['SELECT * FROM users'];
        yield 'select lowercase' => ['select id from users'];
        yield 'EXPLAIN' => ['EXPLAIN SELECT * FROM users'];
        yield 'DESCRIBE' => ['DESCRIBE users'];
        yield 'SHOW' => ['SHOW TABLES'];
        yield 'PRAGMA' => ['PRAGMA table_info(users)'];
        yield 'WITH CTE + SELECT' => ['WITH cte AS (SELECT 1) SELECT * FROM cte'];
        yield 'WITH RECURSIVE CTE' => ['WITH RECURSIVE cte AS (SELECT 1 UNION ALL SELECT n+1 FROM cte WHERE n < 10) SELECT * FROM cte'];
        yield 'WITH multiple CTEs' => ['WITH a AS (SELECT 1), b AS (SELECT 2) SELECT * FROM a, b'];
        yield 'WITH nested subquery' => ['WITH cte AS (SELECT * FROM (SELECT 1) sub) SELECT * FROM cte'];
        yield 'leading whitespace' => ['  SELECT 1'];
        yield 'with comment' => ["-- comment\nSELECT 1"];
        yield 'EXPLAIN ANALYZE SELECT' => ['EXPLAIN ANALYZE SELECT * FROM users'];
        yield 'SELECT with into_field alias' => ['SELECT into_field FROM users'];
        yield 'WITH CTE containing string with parens' => ["WITH cte AS (SELECT '(hello)' AS val) SELECT * FROM cte"];
    }

    #[Test]
    #[DataProvider('blockedQueryProvider')]
    public function queryBlocksWriteStatements(string $sql): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->connection->query($sql);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedQueryProvider(): iterable
    {
        yield 'INSERT' => ['INSERT INTO users (name) VALUES ("test")'];
        yield 'UPDATE' => ['UPDATE users SET name = "test"'];
        yield 'DELETE' => ['DELETE FROM users'];
        yield 'DROP' => ['DROP TABLE users'];
        yield 'CREATE' => ['CREATE TABLE test (id INT)'];
        yield 'ALTER' => ['ALTER TABLE users ADD COLUMN age INT'];
        yield 'TRUNCATE' => ['TRUNCATE users'];
        yield 'WITH CTE + INSERT' => ['WITH cte AS (SELECT 1) INSERT INTO users (id) SELECT * FROM cte'];
        yield 'WITH CTE + UPDATE' => ['WITH cte AS (SELECT 1) UPDATE users SET active = 1'];
        yield 'WITH CTE + DELETE' => ['WITH cte AS (SELECT 1) DELETE FROM users WHERE id IN (SELECT * FROM cte)'];
        yield 'EXPLAIN ANALYZE INSERT' => ['EXPLAIN ANALYZE INSERT INTO users (name) VALUES ("test")'];
        yield 'EXPLAIN ANALYZE DELETE' => ['EXPLAIN ANALYZE DELETE FROM users'];
        yield 'SELECT INTO OUTFILE' => ['SELECT * FROM users INTO OUTFILE "/tmp/data.csv"'];
        yield 'SELECT INTO DUMPFILE' => ['SELECT * FROM users INTO DUMPFILE "/tmp/data.bin"'];
        yield 'MySQL conditional comment INSERT' => ['/*!40000 INSERT INTO users VALUES (1) */'];
    }

    #[Test]
    public function executeThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/execute/');

        $this->connection->execute('INSERT INTO users (name) VALUES ("test")');
    }

    #[Test]
    public function preparePassesReadOnlyValidation(): void
    {
        // Verify prepare() does not throw ReplSafeModeException for read-only SQL.
        // Statement is final — the inner mock can't return it, so we catch that separately.
        $blocked = false;

        try {
            $this->connection->prepare('SELECT * FROM users WHERE id = :id');
        } catch (ReplSafeModeException) {
            $blocked = true;
        } catch (Throwable) {
            // Expected — inner stub can't return final Statement class
        }

        self::assertFalse($blocked, 'prepare() should not throw ReplSafeModeException for read-only SQL');
    }

    #[Test]
    public function prepareBlocksWriteQueries(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->connection->prepare('DELETE FROM users WHERE id = :id');
    }

    #[Test]
    public function beginTransactionThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/beginTransaction/');

        $this->connection->beginTransaction();
    }

    #[Test]
    public function transactionThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/transaction/');

        $this->connection->transaction(fn() => null);
    }

    #[Test]
    public function lastInsertIdReturnsZero(): void
    {
        self::assertSame('0', $this->connection->lastInsertId());
    }

    #[Test]
    public function driverDelegates(): void
    {
        $this->inner->method('driver')->willReturn(Driver::SQLite);

        self::assertSame(Driver::SQLite, $this->connection->driver());
    }

    #[Test]
    public function nameDelegates(): void
    {
        $this->inner->method('name')->willReturn('default');

        self::assertSame('default', $this->connection->name());
    }

    #[Test]
    public function inTransactionDelegates(): void
    {
        $this->inner->method('inTransaction')->willReturn(false);

        self::assertFalse($this->connection->inTransaction());
    }

    #[Test]
    public function disconnectDelegates(): void
    {
        $mock = $this->createMock(ConnectionInterface::class);
        $mock->expects(self::once())->method('disconnect');

        $connection = new ReadOnlyConnection($mock);
        $connection->disconnect();
    }
}
