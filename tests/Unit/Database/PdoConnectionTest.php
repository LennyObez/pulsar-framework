<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Param;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use RuntimeException;

#[CoversClass(PdoConnection::class)]
final class PdoConnectionTest extends TestCase
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

        $this->connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER, active INTEGER DEFAULT 1, data BLOB)');
        $this->connection->execute("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice@example.com', 30)");
        $this->connection->execute("INSERT INTO users (name, email, age) VALUES ('Bob', 'bob@example.com', 25)");
    }

    #[Test]
    public function queryReturnsResult(): void
    {
        $result = $this->connection->query('SELECT * FROM users ORDER BY id');

        self::assertInstanceOf(Result::class, $result);
        self::assertSame(2, $result->rowCount);
        self::assertSame('Alice', $result->rows[0]->getString('name'));
        self::assertSame('Bob', $result->rows[1]->getString('name'));
    }

    #[Test]
    public function queryWithBindings(): void
    {
        $result = $this->connection->query('SELECT * FROM users WHERE name = :name', ['name' => 'Alice']);

        self::assertSame(1, $result->rowCount);
        self::assertSame('alice@example.com', $result->rows[0]->getString('email'));
    }

    #[Test]
    public function queryWithIntBindings(): void
    {
        $result = $this->connection->query('SELECT * FROM users WHERE age > :age', ['age' => 26]);

        self::assertSame(1, $result->rowCount);
        self::assertSame('Alice', $result->rows[0]->getString('name'));
    }

    #[Test]
    public function queryWithNullBinding(): void
    {
        $this->connection->execute('INSERT INTO users (name, email, age) VALUES (:name, :email, :age)', [
            'name' => 'Charlie',
            'email' => null,
            'age' => 35,
        ]);

        $result = $this->connection->query('SELECT * FROM users WHERE email IS NULL');
        self::assertSame(1, $result->rowCount);
        self::assertSame('Charlie', $result->rows[0]->getString('name'));
    }

    #[Test]
    public function queryWithBoolBinding(): void
    {
        $result = $this->connection->query('SELECT * FROM users WHERE active = :active', ['active' => true]);

        self::assertSame(2, $result->rowCount);
    }

    #[Test]
    public function queryWithParamBinding(): void
    {
        $binaryData = random_bytes(16);
        $this->connection->execute('INSERT INTO users (name, data) VALUES (:name, :data)', [
            'name' => 'BinaryUser',
            'data' => Param::binary($binaryData),
        ]);

        $result = $this->connection->query('SELECT data FROM users WHERE name = :name', ['name' => 'BinaryUser']);
        self::assertSame(1, $result->rowCount);
        self::assertSame($binaryData, $result->rows[0]->getBinary('data'));
    }

    #[Test]
    public function executeReturnsAffectedRowCount(): void
    {
        $count = $this->connection->execute('UPDATE users SET age = age + 1');

        self::assertSame(2, $count);
    }

    #[Test]
    public function executeWithBindings(): void
    {
        $count = $this->connection->execute('UPDATE users SET age = :age WHERE name = :name', [
            'age' => 99,
            'name' => 'Alice',
        ]);

        self::assertSame(1, $count);

        $result = $this->connection->query('SELECT age FROM users WHERE name = :name', ['name' => 'Alice']);
        self::assertSame(99, $result->rows[0]->getInt('age'));
    }

    #[Test]
    public function prepareReturnsStatement(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM users WHERE name = :name');

        self::assertInstanceOf(Statement::class, $stmt);

        $result = $stmt->bind('name', 'Alice')->execute();
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function beginTransactionReturnsTransaction(): void
    {
        $txn = $this->connection->beginTransaction();

        self::assertInstanceOf(Transaction::class, $txn);
        self::assertTrue($txn->active);
        self::assertTrue($this->connection->inTransaction());

        $txn->commit();
        // Note: beginTransaction/commit directly does not auto-decrement depth,
        // that is handled by the transaction() wrapper method.
        // So inTransaction() remains true after direct commit.
        self::assertTrue($this->connection->inTransaction());
    }

    #[Test]
    public function transactionCommitsOnSuccess(): void
    {
        $result = $this->connection->transaction(function (ConnectionInterface $conn): string {
            $conn->execute("INSERT INTO users (name, email, age) VALUES ('Charlie', 'charlie@test.com', 35)");

            return 'done';
        });

        self::assertSame('done', $result);

        $count = $this->connection->query('SELECT COUNT(*) as cnt FROM users');
        self::assertSame(3, $count->rows[0]->getInt('cnt'));
    }

    #[Test]
    public function transactionRollsBackOnException(): void
    {
        try {
            $this->connection->transaction(function (ConnectionInterface $conn): void {
                $conn->execute("INSERT INTO users (name, email, age) VALUES ('Charlie', 'charlie@test.com', 35)");

                throw new RuntimeException('Something went wrong');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $count = $this->connection->query('SELECT COUNT(*) as cnt FROM users');
        self::assertSame(2, $count->rows[0]->getInt('cnt'));
    }

    #[Test]
    public function nestedTransactionsWithSavepoints(): void
    {
        $txn1 = $this->connection->beginTransaction();
        self::assertSame(0, $txn1->depth());
        self::assertTrue($this->connection->inTransaction());

        $txn2 = $this->connection->beginTransaction();
        self::assertSame(1, $txn2->depth());

        $this->connection->execute("INSERT INTO users (name, email, age) VALUES ('Nested', 'nested@test.com', 40)");

        $txn2->rollback();
        $txn1->commit();

        $result = $this->connection->query("SELECT * FROM users WHERE name = 'Nested'");
        self::assertSame(0, $result->rowCount);
    }

    #[Test]
    public function lastInsertIdReturnsId(): void
    {
        $this->connection->execute("INSERT INTO users (name, email, age) VALUES ('New', 'new@test.com', 20)");

        $id = $this->connection->lastInsertId();
        self::assertSame('3', $id);
    }

    #[Test]
    public function driverReturnsCorrectDriver(): void
    {
        self::assertSame(Driver::SQLite, $this->connection->driver());
    }

    #[Test]
    public function nameReturnsConnectionName(): void
    {
        self::assertSame('test', $this->connection->name());
    }

    #[Test]
    public function inTransactionReturnsFalseWhenNoTransaction(): void
    {
        self::assertFalse($this->connection->inTransaction());
    }

    #[Test]
    public function disconnectResetsConnection(): void
    {
        // First query to initialize the connection
        $this->connection->query('SELECT 1');

        $this->connection->disconnect();

        self::assertFalse($this->connection->inTransaction());

        // Should reconnect lazily
        $result = $this->connection->query('SELECT 1 as val');
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function queryThrowsDatabaseExceptionOnError(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connection->query('SELECT * FROM nonexistent_table');
    }

    #[Test]
    public function executeThrowsDatabaseExceptionOnError(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connection->execute('DROP TABLE nonexistent_table');
    }

    #[Test]
    public function prepareThrowsDatabaseExceptionOnError(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connection->prepare('INVALID SQL STATEMENT HERE THAT CANNOT BE PREPARED (((');
    }

    #[Test]
    public function connectionFailedThrowsDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);

        $conn = new PdoConnection(
            connectionName: 'bad',
            driver: Driver::MySQL,
            dsn: 'mysql:host=192.0.2.1;port=3306;dbname=nonexistent',
            username: 'root',
            password: 'wrong',
            options: [PDO::ATTR_TIMEOUT => 1],
        );

        // Trigger lazy connection
        $conn->query('SELECT 1');
    }

    #[Test]
    public function fromConfigCreatesConnection(): void
    {
        $config = new \Pulsar\Config\ConnectionConfig(
            name: 'test_from_config',
            driver: Driver::SQLite,
            host: '',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
            options: [],
        );

        $conn = PdoConnection::fromConfig($config);

        self::assertSame('test_from_config', $conn->name());
        self::assertSame(Driver::SQLite, $conn->driver());

        // Should be able to query
        $result = $conn->query('SELECT 1 as val');
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function queryHandlesColonPrefixedBindings(): void
    {
        $result = $this->connection->query('SELECT * FROM users WHERE name = :name', [':name' => 'Alice']);

        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function sqlitePragmaForeignKeysEnabled(): void
    {
        $result = $this->connection->query('PRAGMA foreign_keys');
        self::assertSame(1, $result->rows[0]->getInt('foreign_keys'));
    }
}
