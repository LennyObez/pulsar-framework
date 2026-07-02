<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Orm\Features\Persistence\TransactionManager;

final class TransactionManagerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private TransactionManager $manager;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->manager = new TransactionManager($this->connection);
    }

    #[Test]
    public function transactionalDelegatesToConnection(): void
    {
        $this->connection->method('transaction')->willReturnCallback(
            fn(callable $cb) => $cb(),
        );

        $result = $this->manager->transactional(fn() => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function inTransactionDelegatesToConnection(): void
    {
        $this->connection->method('inTransaction')->willReturn(true);

        self::assertTrue($this->manager->inTransaction());
    }

    #[Test]
    public function inTransactionReturnsFalseWhenNotActive(): void
    {
        $this->connection->method('inTransaction')->willReturn(false);

        self::assertFalse($this->manager->inTransaction());
    }

    #[Test]
    public function commitWithoutBeginIsNoop(): void
    {
        // Should not throw
        $this->manager->commit();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rollbackWithoutBeginIsNoop(): void
    {
        // Should not throw
        $this->manager->rollback();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function nestedBeginRetainsOuterTransactionHandle(): void
    {
        // FR-32: a nested begin() must not overwrite (and lose) the outer
        // transaction handle. With a single reference, the second commit() is a
        // no-op and the outer real transaction stays open; a stack commits both
        // levels, so the connection ends with no open transaction.
        $connection = PdoConnection::fromConfig(new ConnectionConfig(
            name: 'tx_test',
            driver: Driver::SQLite,
            host: '',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
            options: [],
        ));
        $manager = new TransactionManager($connection);

        $manager->begin();
        $manager->begin();
        $manager->commit();
        $manager->commit();

        self::assertFalse(
            $connection->inTransaction(),
            'the outer transaction must be committed, not left dangling',
        );
    }
}
