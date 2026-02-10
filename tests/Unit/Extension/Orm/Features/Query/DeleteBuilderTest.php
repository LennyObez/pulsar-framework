<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Features\Query\DeleteBuilder;

use function count;
use function in_array;

#[CoversClass(DeleteBuilder::class)]
final class DeleteBuilderTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    #[Test]
    public function executeWithWhereCondition(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'DELETE FROM `users`')
                        && str_contains($sql, 'WHERE')
                        && str_contains($sql, '`id`');
                }),
                self::callback(static function (array $bindings): bool {
                    return in_array(42, array_values($bindings), true);
                }),
            )
            ->willReturn(1);

        $affected = new DeleteBuilder($connection, 'users')
            ->where('id', 42)
            ->execute();

        self::assertSame(1, $affected);
    }

    #[Test]
    public function executeWithMultipleWhereConditions(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'WHERE')
                        && str_contains($sql, 'AND');
                }),
                self::anything(),
            )
            ->willReturn(5);

        $affected = new DeleteBuilder($connection, 'users')
            ->where('status', 'inactive')
            ->where('role', 'guest')
            ->execute();

        self::assertSame(5, $affected);
    }

    #[Test]
    public function executeWithNoWhereDeletesAll(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'DELETE FROM')
                        && !str_contains($sql, 'WHERE');
                }),
                self::anything(),
            )
            ->willReturn(50);

        $affected = new DeleteBuilder($connection, 'users')
            ->execute();

        self::assertSame(50, $affected);
    }

    #[Test]
    public function whereReturnsFluentInterface(): void
    {
        $builder = new DeleteBuilder($this->connection, 'users');
        $result = $builder->where('id', 1);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function executeReturnsZeroWhenNoRowsDeleted(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->willReturn(0);

        $affected = new DeleteBuilder($connection, 'users')
            ->where('id', 999999)
            ->execute();

        self::assertSame(0, $affected);
    }

    #[Test]
    public function bindingsAreUnique(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $bindings): bool {
                    // Multiple WHERE conditions should have unique binding names
                    return count($bindings) === 3;
                }),
            )
            ->willReturn(1);

        new DeleteBuilder($connection, 'users')
            ->where('a', 1)
            ->where('b', 2)
            ->where('c', 3)
            ->execute();
    }
}
