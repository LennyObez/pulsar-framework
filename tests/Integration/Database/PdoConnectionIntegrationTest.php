<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\PdoConnection;

#[CoversClass(PdoConnection::class)]
final class PdoConnectionIntegrationTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        // Create a test table
        $this->connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)',
        );
    }

    #[Test]
    public function executeInsertsAndReturnsAffectedCount(): void
    {
        $count = $this->connection->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );

        self::assertSame(1, $count);
    }

    #[Test]
    public function queryReturnsResultWithRows(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );
        $this->connection->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        );

        $result = $this->connection->query('SELECT * FROM users ORDER BY id');

        self::assertSame(2, $result->rowCount);
        self::assertSame('Alice', $result->rows[0]->getString('name'));
        self::assertSame('Bob', $result->rows[1]->getString('name'));
    }

    #[Test]
    public function queryWithBindings(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, email, active) VALUES (:name, :email, :active)',
            ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1],
        );
        $this->connection->execute(
            'INSERT INTO users (name, email, active) VALUES (:name, :email, :active)',
            ['name' => 'Bob', 'email' => 'bob@example.com', 'active' => 0],
        );

        $result = $this->connection->query(
            'SELECT * FROM users WHERE active = :active',
            ['active' => 1],
        );

        self::assertSame(1, $result->rowCount);
        self::assertSame('Alice', $result->first()?->getString('name'));
    }

    #[Test]
    public function lastInsertIdReturnsCorrectValue(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );

        self::assertSame('1', $this->connection->lastInsertId());

        $this->connection->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        );

        self::assertSame('2', $this->connection->lastInsertId());
    }

    #[Test]
    public function prepareAndExecuteStatement(): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
        );

        $affected = $stmt->bind('name', 'Alice')
            ->bind('email', 'alice@example.com')
            ->executeAffecting();

        self::assertSame(1, $affected);

        $result = $this->connection->query('SELECT * FROM users');
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function prepareAndQueryStatement(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );

        $stmt = $this->connection->prepare('SELECT * FROM users WHERE name = :name');
        $result = $stmt->execute(['name' => 'Alice']);

        self::assertSame(1, $result->rowCount);
        self::assertSame('alice@example.com', $result->first()?->getString('email'));
    }

    #[Test]
    public function typedRowAccessorsWork(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, email, active) VALUES (:name, :email, :active)',
            ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1],
        );

        $row = $this->connection->query('SELECT * FROM users WHERE id = 1')->firstOrFail();

        self::assertSame(1, $row->getInt('id'));
        self::assertSame('Alice', $row->getString('name'));
        self::assertTrue($row->getBool('active'));
        self::assertTrue($row->has('email'));
        self::assertFalse($row->has('nonexistent'));
    }

    #[Test]
    public function resultPluckExtractsColumn(): void
    {
        $this->connection->execute('INSERT INTO users (name, email) VALUES (:name, :email)', ['name' => 'Alice', 'email' => 'a@x.com']);
        $this->connection->execute('INSERT INTO users (name, email) VALUES (:name, :email)', ['name' => 'Bob', 'email' => 'b@x.com']);

        $result = $this->connection->query('SELECT * FROM users ORDER BY id');
        $names = $result->pluck('name');

        self::assertSame(['Alice', 'Bob'], $names);
    }

    #[Test]
    public function emptyQueryReturnsEmptyResult(): void
    {
        $result = $this->connection->query('SELECT * FROM users');

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->rowCount);
        self::assertNull($result->first());
    }

    #[Test]
    public function driverReturnsSqlite(): void
    {
        self::assertSame(Driver::SQLite, $this->connection->driver());
    }

    #[Test]
    public function nameReturnsConnectionName(): void
    {
        self::assertSame('test', $this->connection->name());
    }

    #[Test]
    public function inTransactionReturnsFalseByDefault(): void
    {
        self::assertFalse($this->connection->inTransaction());
    }

    #[Test]
    public function disconnectResetsConnection(): void
    {
        // Execute a query to create the PDO connection
        $this->connection->query('SELECT 1');

        $this->connection->disconnect();

        // After disconnect, the next query should create a fresh connection.
        // But since the old SQLite in-memory DB is lost, the table won't exist.
        $this->expectException(DatabaseException::class);
        $this->connection->query('SELECT * FROM users');
    }

    #[Test]
    public function nullBindingsAreHandled(): void
    {
        $this->connection->execute(
            'CREATE TABLE nullable_test (id INTEGER PRIMARY KEY, value TEXT)',
        );

        $this->connection->execute(
            'INSERT INTO nullable_test (id, value) VALUES (:id, :value)',
            ['id' => 1, 'value' => null],
        );

        $result = $this->connection->query('SELECT * FROM nullable_test WHERE id = 1');
        $row = $result->firstOrFail();

        self::assertNull($row->getNullableString('value'));
    }

    #[Test]
    public function updateReturnsAffectedRowCount(): void
    {
        $this->connection->execute('INSERT INTO users (name, email) VALUES (:name, :email)', ['name' => 'Alice', 'email' => 'a@x.com']);
        $this->connection->execute('INSERT INTO users (name, email) VALUES (:name, :email)', ['name' => 'Bob', 'email' => 'b@x.com']);

        $affected = $this->connection->execute(
            'UPDATE users SET active = :active WHERE active = :current',
            ['active' => 0, 'current' => 1],
        );

        self::assertSame(2, $affected);
    }

    #[Test]
    public function deleteReturnsAffectedRowCount(): void
    {
        $this->connection->execute('INSERT INTO users (name, email) VALUES (:name, :email)', ['name' => 'Alice', 'email' => 'a@x.com']);
        $this->connection->execute('INSERT INTO users (name, email) VALUES (:name, :email)', ['name' => 'Bob', 'email' => 'b@x.com']);

        $affected = $this->connection->execute('DELETE FROM users WHERE name = :name', ['name' => 'Alice']);

        self::assertSame(1, $affected);
    }
}
