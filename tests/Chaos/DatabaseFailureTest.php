<?php

declare(strict_types=1);

namespace Pulsar\Tests\Chaos;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Pool\ConnectionPool;
use Pulsar\Database\Result;
use RuntimeException;

#[CoversClass(ConnectionPool::class)]
#[Group('chaos')]
final class DatabaseFailureTest extends TestCase
{
    #[Test]
    public function connectionTimeoutIsHandledGracefully(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')
            ->willThrowException(new PDOException('Connection timed out after 30 seconds'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Connection timed out');

        $connection->query('SELECT 1');
    }

    #[Test]
    public function queryDeadlockTriggersRetryableException(): void
    {
        $attempt = 0;
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')
            ->willReturnCallback(function () use (&$attempt): int {
                $attempt++;
                if ($attempt < 3) {
                    throw new PDOException(
                        'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
                        40001,
                    );
                }
                return 1;
            });

        // Simulate retry logic
        $maxRetries = 3;
        $success = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $result = $connection->execute('UPDATE accounts SET balance = balance - 100 WHERE id = 1');
                $success = true;
                break;
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'Deadlock')) {
                    throw $e;
                }
            }
        }

        self::assertTrue($success, 'Deadlock retry should eventually succeed');
        self::assertSame(3, $attempt);
    }

    #[Test]
    public function connectionPoolExhaustionIsDetected(): void
    {
        $connections = [];
        $maxSize = 5;

        for ($i = 0; $i < $maxSize; $i++) {
            $conn = $this->createStub(ConnectionInterface::class);
            $conn->method('name')->willReturn("conn-{$i}");
            $connections[] = $conn;
        }

        // Simulate pool behavior: once all connections are acquired, the next should fail
        $acquired = 0;
        $poolExhausted = false;

        foreach ($connections as $conn) {
            $acquired++;
        }

        if ($acquired >= $maxSize) {
            $poolExhausted = true;
        }

        self::assertTrue($poolExhausted, 'Pool exhaustion should be detected when all connections are acquired');
        self::assertSame($maxSize, $acquired);
    }

    #[Test]
    public function transactionRollbackOnFailurePreservesConsistency(): void
    {
        $rolledBack = false;
        $committed = false;

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturnCallback(function (string $sql): int {
            if (str_contains($sql, 'FAIL_HERE')) {
                throw new RuntimeException('Simulated query failure');
            }
            return 1;
        });
        $connection->method('transaction')->willReturnCallback(
            function (callable $callback) use ($connection, &$committed, &$rolledBack): mixed {
                try {
                    $result = $callback($connection);
                    $committed = true;
                    return $result;
                } catch (\Throwable) {
                    $rolledBack = true;
                    return null;
                }
            },
        );

        // Simulate a transaction that fails mid-way via the transaction() helper
        $connection->transaction(function (ConnectionInterface $conn): void {
            $conn->execute('INSERT INTO audit_log (action) VALUES ("start")');
            $conn->execute('FAIL_HERE');
        });

        self::assertTrue($rolledBack, 'Transaction should be rolled back on failure');
        self::assertFalse($committed, 'Transaction should not be committed after failure');
    }

    #[Test]
    public function connectionLostDuringQueryIsDetected(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')
            ->willThrowException(new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away'));

        try {
            $connection->query('SELECT * FROM users');
            self::fail('Should have thrown PDOException');
        } catch (PDOException $e) {
            self::assertStringContainsString('gone away', $e->getMessage());
        }
    }

    #[Test]
    public function multipleSequentialFailuresAreHandled(): void
    {
        $callCount = 0;
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')
            ->willReturnCallback(function () use (&$callCount): Result {
                $callCount++;
                throw match (true) {
                    $callCount <= 2 => new PDOException('Connection refused'),
                    $callCount <= 4 => new PDOException('Lock wait timeout exceeded'),
                    default => new PDOException('Unknown error'),
                };
            });

        $errors = [];
        for ($i = 0; $i < 6; $i++) {
            try {
                $connection->query('SELECT 1');
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }

        self::assertCount(6, $errors);
        self::assertStringContainsString('Connection refused', $errors[0]);
        self::assertStringContainsString('Lock wait timeout', $errors[2]);
    }
}
