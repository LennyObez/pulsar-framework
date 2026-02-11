<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\ColumnInfo;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Introspection\TableInfo;
use Pulsar\Database\PdoConnection;

#[CoversClass(DatabaseIntrospector::class)]
#[CoversClass(TableInfo::class)]
#[CoversClass(ColumnInfo::class)]
final class DatabaseIntrospectorTest extends TestCase
{
    private PdoConnection $connection;
    private DatabaseIntrospector $introspector;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, is_active INTEGER DEFAULT 1)');
        $this->connection->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL, body TEXT, created_at TEXT)');

        $this->introspector = new DatabaseIntrospector($this->connection);
    }

    #[Test]
    public function tablesReturnsAllUserTables(): void
    {
        $tables = $this->introspector->tables();

        self::assertCount(2, $tables);
        self::assertContainsOnlyInstancesOf(TableInfo::class, $tables);

        $names = array_map(static fn(TableInfo $t): string => $t->name, $tables);
        self::assertContains('users', $names);
        self::assertContains('posts', $names);
    }

    #[Test]
    public function tablesExcludesSqliteInternalTables(): void
    {
        $tables = $this->introspector->tables();
        $names = array_map(static fn(TableInfo $t): string => $t->name, $tables);

        foreach ($names as $name) {
            self::assertStringStartsNotWith('sqlite_', $name);
        }
    }

    #[Test]
    public function columnsReturnsAllColumnsForTable(): void
    {
        $columns = $this->introspector->columns('users');

        self::assertCount(4, $columns);
        self::assertContainsOnlyInstancesOf(ColumnInfo::class, $columns);

        $names = array_map(static fn(ColumnInfo $c): string => $c->name, $columns);
        self::assertSame(['id', 'name', 'email', 'is_active'], $names);
    }

    #[Test]
    public function columnInfoReflectsSchemaProperties(): void
    {
        $columns = $this->introspector->columns('users');

        $idCol = $columns[0];
        self::assertSame('id', $idCol->name);
        self::assertTrue($idCol->isPrimaryKey);

        $nameCol = $columns[1];
        self::assertSame('name', $nameCol->name);
        self::assertFalse($nameCol->nullable);
        self::assertFalse($nameCol->isPrimaryKey);

        $emailCol = $columns[2];
        self::assertSame('email', $emailCol->name);
        self::assertTrue($emailCol->nullable);

        $activeCol = $columns[3];
        self::assertSame('is_active', $activeCol->name);
        self::assertSame('1', $activeCol->default);
    }

    #[Test]
    public function primaryKeyReturnsPrimaryColumnName(): void
    {
        self::assertSame('id', $this->introspector->primaryKey('users'));
        self::assertSame('id', $this->introspector->primaryKey('posts'));
    }

    #[Test]
    public function primaryKeyReturnsNullForTableWithoutPk(): void
    {
        $this->connection->execute('CREATE TABLE logs (message TEXT, level TEXT)');

        self::assertNull($this->introspector->primaryKey('logs'));
    }

    #[Test]
    public function emptyDatabaseReturnsNoTables(): void
    {
        $emptyConn = new PdoConnection(
            connectionName: 'empty',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $introspector = new DatabaseIntrospector($emptyConn);

        self::assertSame([], $introspector->tables());
    }

    #[Test]
    public function columnTypeIsLowercased(): void
    {
        $columns = $this->introspector->columns('users');

        foreach ($columns as $column) {
            self::assertSame(strtolower($column->type), $column->type);
        }
    }

    #[Test]
    public function columnsForPostsTable(): void
    {
        $columns = $this->introspector->columns('posts');

        self::assertCount(5, $columns);

        $names = array_map(static fn(ColumnInfo $c): string => $c->name, $columns);
        self::assertSame(['id', 'user_id', 'title', 'body', 'created_at'], $names);

        // id is primary key
        self::assertTrue($columns[0]->isPrimaryKey);

        // user_id is NOT NULL
        self::assertFalse($columns[1]->nullable);

        // body is nullable
        self::assertTrue($columns[3]->nullable);
    }

    #[Test]
    public function primaryKeyReturnsNullForNoPrimaryKey(): void
    {
        $this->connection->execute('CREATE TABLE no_pk (a TEXT, b TEXT)');

        self::assertNull($this->introspector->primaryKey('no_pk'));
    }

    #[Test]
    public function tablesReturnsCorrectCount(): void
    {
        $this->connection->execute('CREATE TABLE extra_table (id INTEGER PRIMARY KEY)');

        $tables = $this->introspector->tables();
        self::assertCount(3, $tables);
    }

    #[Test]
    public function columnsReturnsDefaultValues(): void
    {
        $columns = $this->introspector->columns('users');

        $activeCol = $columns[3];
        self::assertSame('is_active', $activeCol->name);
        self::assertSame('1', $activeCol->default);
    }

    #[Test]
    public function columnsReturnsNullDefaultForNullableWithoutDefault(): void
    {
        $columns = $this->introspector->columns('users');

        $emailCol = $columns[2];
        self::assertSame('email', $emailCol->name);
        self::assertNull($emailCol->default);
    }
}
