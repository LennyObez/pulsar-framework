<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Features\Query\InsertBuilder;

use function count;
use function in_array;

#[CoversClass(InsertBuilder::class)]
final class InsertBuilderTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    #[Test]
    public function executeInsertsRowAndReturnsId(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'INSERT INTO `users`')
                        && str_contains($sql, '`name`')
                        && str_contains($sql, '`email`')
                        && str_contains($sql, 'VALUES');
                }),
                self::callback(static function (array $bindings): bool {
                    $values = array_values($bindings);

                    return in_array('Alice', $values, true)
                        && in_array('alice@example.com', $values, true);
                }),
            )
            ->willReturn(1);

        $connection->expects(self::once())
            ->method('lastInsertId')
            ->willReturn('42');

        $id = new InsertBuilder($connection, 'users')
            ->values(['name' => 'Alice', 'email' => 'alice@example.com'])
            ->execute();

        self::assertSame('42', $id);
    }

    #[Test]
    public function valuesReturnsFluentInterface(): void
    {
        $builder = new InsertBuilder($this->connection, 'users');
        $result = $builder->values(['name' => 'Test']);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function executeSingleColumnInsert(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, '`status`');
                }),
                self::anything(),
            )
            ->willReturn(1);

        $connection->method('lastInsertId')->willReturn('1');

        $id = new InsertBuilder($connection, 'flags')
            ->values(['status' => 'active'])
            ->execute();

        self::assertSame('1', $id);
    }

    #[Test]
    public function executeMultipleColumnsInsert(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, '`first_name`')
                        && str_contains($sql, '`last_name`')
                        && str_contains($sql, '`age`');
                }),
                self::callback(static function (array $bindings): bool {
                    return count($bindings) === 3;
                }),
            )
            ->willReturn(1);

        $connection->method('lastInsertId')->willReturn('10');

        $id = new InsertBuilder($connection, 'people')
            ->values(['first_name' => 'John', 'last_name' => 'Doe', 'age' => 30])
            ->execute();

        self::assertSame('10', $id);
    }

    #[Test]
    public function executeWithNullValues(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $bindings): bool {
                    return in_array(null, array_values($bindings), true);
                }),
            )
            ->willReturn(1);

        $connection->method('lastInsertId')->willReturn('5');

        $id = new InsertBuilder($connection, 'users')
            ->values(['name' => 'Bob', 'deleted_at' => null])
            ->execute();

        self::assertSame('5', $id);
    }

    #[Test]
    public function valuesOverwritesPreviousValues(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, '`role`')
                        && !str_contains($sql, '`name`');
                }),
                self::anything(),
            )
            ->willReturn(1);

        $connection->method('lastInsertId')->willReturn('1');

        new InsertBuilder($connection, 'users')
            ->values(['name' => 'First'])
            ->values(['role' => 'admin'])
            ->execute();
    }
}
