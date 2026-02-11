<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Features\Schema\SchemaBuilder;
use Pulsar\Extension\Orm\Features\Schema\TableBuilder;

#[CoversClass(SchemaBuilder::class)]
final class SchemaBuilderTest extends TestCase
{
    #[Test]
    public function createExecutesDdlStatements(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::atLeastOnce())
            ->method('execute')
            ->with(self::callback(static function (string $sql): bool {
                return str_contains($sql, 'CREATE TABLE')
                    && str_contains($sql, '`users`');
            }));

        $schema = new SchemaBuilder($connection);
        $schema->create('users', static function (TableBuilder $table): void {
            $table->id();
            $table->string('name');
        });
    }

    #[Test]
    public function dropExecutesDropTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (string $sql): bool {
                return str_contains($sql, 'DROP TABLE')
                    && str_contains($sql, '`users`')
                    && !str_contains($sql, 'IF EXISTS');
            }));

        $schema = new SchemaBuilder($connection);
        $schema->drop('users');
    }

    #[Test]
    public function dropIfExistsExecutesConditionalDrop(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (string $sql): bool {
                return str_contains($sql, 'DROP TABLE IF EXISTS')
                    && str_contains($sql, '`users`');
            }));

        $schema = new SchemaBuilder($connection);
        $schema->dropIfExists('users');
    }

    #[Test]
    public function renameMysqlUsesRenameTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (string $sql): bool {
                return str_contains($sql, 'RENAME TABLE')
                    && str_contains($sql, '`old_table`')
                    && str_contains($sql, '`new_table`');
            }));

        $schema = new SchemaBuilder($connection);
        $schema->rename('old_table', 'new_table');
    }

    #[Test]
    public function renameSqliteUsesAlterTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (string $sql): bool {
                return str_contains($sql, 'ALTER TABLE')
                    && str_contains($sql, 'RENAME TO');
            }));

        $schema = new SchemaBuilder($connection);
        $schema->rename('old_table', 'new_table');
    }

    #[Test]
    public function hasTableReturnsTrueWhenTableExists(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $result = new Result([new Row(['count' => 1])]);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('information_schema.tables'),
                self::callback(static fn(array $b): bool => $b['table'] === 'users'),
            )
            ->willReturn($result);

        $schema = new SchemaBuilder($connection);

        self::assertTrue($schema->hasTable('users'));
    }

    #[Test]
    public function hasTableReturnsFalseWhenTableDoesNotExist(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $result = new Result([]);
        $connection->expects(self::once())
            ->method('query')
            ->willReturn($result);

        $schema = new SchemaBuilder($connection);

        self::assertFalse($schema->hasTable('nonexistent'));
    }

    #[Test]
    public function hasColumnReturnsTrueWhenColumnExists(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $result = new Result([new Row(['count' => 1])]);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('information_schema.columns'),
                self::callback(static fn(array $b): bool => $b['table'] === 'users' && $b['column'] === 'email'),
            )
            ->willReturn($result);

        $schema = new SchemaBuilder($connection);

        self::assertTrue($schema->hasColumn('users', 'email'));
    }

    #[Test]
    public function hasColumnReturnsFalseWhenColumnDoesNotExist(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $result = new Result([]);
        $connection->expects(self::once())
            ->method('query')
            ->willReturn($result);

        $schema = new SchemaBuilder($connection);

        self::assertFalse($schema->hasColumn('users', 'nonexistent'));
    }

    #[Test]
    public function tableAlterExecutesAlterStatements(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $executedStatements = [];
        $connection->expects(self::atLeastOnce())
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$executedStatements): int {
                $executedStatements[] = $sql;

                return 0;
            });

        $schema = new SchemaBuilder($connection);
        $schema->table('users', static function (TableBuilder $table): void {
            $table->string('middle_name')->nullable();
        });

        self::assertNotEmpty($executedStatements);
        self::assertStringContainsString('ALTER TABLE', $executedStatements[0]);
        self::assertStringContainsString('ADD COLUMN', $executedStatements[0]);
    }

    #[Test]
    public function createWithIndexesGeneratesMultipleStatements(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $executedStatements = [];
        $connection->expects(self::atLeastOnce())
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$executedStatements): int {
                $executedStatements[] = $sql;

                return 0;
            });

        $schema = new SchemaBuilder($connection);
        $schema->create('users', static function (TableBuilder $table): void {
            $table->id();
            $table->string('email');
            $table->index(['email']);
        });

        $hasCreateTable = false;
        $hasCreateIndex = false;
        foreach ($executedStatements as $stmt) {
            if (str_contains($stmt, 'CREATE TABLE')) {
                $hasCreateTable = true;
            }
            if (str_contains($stmt, 'CREATE INDEX')) {
                $hasCreateIndex = true;
            }
        }

        self::assertTrue($hasCreateTable);
        self::assertTrue($hasCreateIndex);
    }
}
