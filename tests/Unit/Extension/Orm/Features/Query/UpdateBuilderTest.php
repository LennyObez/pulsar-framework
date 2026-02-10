<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use function in_array;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Features\Query\UpdateBuilder;

#[CoversClass(UpdateBuilder::class)]
final class UpdateBuilderTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    #[Test]
    public function executeWithSetAndWhereCompilesSql(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'UPDATE `users`')
                        && str_contains($sql, 'SET')
                        && str_contains($sql, '`name`')
                        && str_contains($sql, 'WHERE')
                        && str_contains($sql, '`id`');
                }),
                self::callback(static function (array $bindings): bool {
                    $values = array_values($bindings);

                    return in_array(42, $values, true)
                        && in_array('Alice', $values, true);
                }),
            )
            ->willReturn(1);

        $affected = new UpdateBuilder($connection, 'users')
            ->set(['name' => 'Alice'])
            ->where('id', 42)
            ->execute();

        self::assertSame(1, $affected);
    }

    #[Test]
    public function executeWithMultipleSetValues(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, '`name`')
                        && str_contains($sql, '`email`');
                }),
                self::anything(),
            )
            ->willReturn(1);

        $affected = new UpdateBuilder($connection, 'users')
            ->set(['name' => 'Bob', 'email' => 'bob@example.com'])
            ->where('id', 1)
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
            ->willReturn(3);

        $affected = new UpdateBuilder($connection, 'users')
            ->set(['status' => 'active'])
            ->where('role', 'admin')
            ->where('verified', 1)
            ->execute();

        self::assertSame(3, $affected);
    }

    #[Test]
    public function executeWithNoWhereClause(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'UPDATE')
                        && str_contains($sql, 'SET')
                        && !str_contains($sql, 'WHERE');
                }),
                self::anything(),
            )
            ->willReturn(100);

        $affected = new UpdateBuilder($connection, 'users')
            ->set(['status' => 'inactive'])
            ->execute();

        self::assertSame(100, $affected);
    }

    #[Test]
    public function setReturnsFluentInterface(): void
    {
        $builder = new UpdateBuilder($this->connection, 'users');
        $result = $builder->set(['name' => 'Test']);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function whereReturnsFluentInterface(): void
    {
        $builder = new UpdateBuilder($this->connection, 'users');
        $result = $builder->where('id', 1);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function executeReturnsZeroWhenNoRowsAffected(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->willReturn(0);

        $affected = new UpdateBuilder($connection, 'users')
            ->set(['name' => 'Ghost'])
            ->where('id', 999999)
            ->execute();

        self::assertSame(0, $affected);
    }
}
