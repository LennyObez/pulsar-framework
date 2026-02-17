<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
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
}
