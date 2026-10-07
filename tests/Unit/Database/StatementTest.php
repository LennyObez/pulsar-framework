<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Param;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Statement;

#[CoversClass(Statement::class)]
final class StatementTest extends TestCase
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

        $this->connection->execute('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER DEFAULT 1, score REAL)');
        $this->connection->execute("INSERT INTO items (name, active, score) VALUES ('alpha', 1, 9.5)");
        $this->connection->execute("INSERT INTO items (name, active, score) VALUES ('beta', 0, 7.2)");
    }

    #[Test]
    public function executeReturnsResult(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE active = :active');

        $result = $stmt->execute(['active' => 1]);

        self::assertCount(1, $result->rows);
        self::assertSame('alpha', $result->rows[0]->getString('name'));
    }

    #[Test]
    public function bindAddsFluently(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE name = :name');

        $result = $stmt->bind('name', 'beta')->execute();

        self::assertCount(1, $result->rows);
        self::assertSame('beta', $result->rows[0]->getString('name'));
    }

    #[Test]
    public function executeAffectingReturnsRowCount(): void
    {
        $stmt = $this->connection->prepare('UPDATE items SET active = :active WHERE name = :name');

        $affected = $stmt->executeAffecting(['active' => 0, 'name' => 'alpha']);

        self::assertSame(1, $affected);
    }

    #[Test]
    public function executeWithNullParameter(): void
    {
        $this->connection->execute('ALTER TABLE items ADD COLUMN description TEXT');

        $stmt = $this->connection->prepare('UPDATE items SET description = :desc WHERE name = :name');

        $affected = $stmt->executeAffecting(['desc' => null, 'name' => 'alpha']);

        self::assertSame(1, $affected);

        $result = $this->connection->query('SELECT description FROM items WHERE name = :name', ['name' => 'alpha']);
        self::assertNull($result->rows[0]->getNullableString('description'));
    }

    #[Test]
    public function executeWithBooleanParameter(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE active = :active');

        $result = $stmt->execute(['active' => true]);

        self::assertCount(1, $result->rows);
    }

    #[Test]
    public function executeWithIntegerParameter(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE id = :id');

        $result = $stmt->execute(['id' => 1]);

        self::assertCount(1, $result->rows);
        self::assertSame('alpha', $result->rows[0]->getString('name'));
    }

    /**
     * A binary {@see Param} bound through the Statement API must be stored with
     * PDO::PARAM_LOB and survive a round-trip byte-for-byte. The earlier
     * implementation lacked a Param branch in bindAll(), so binary values fell
     * through to PDO::PARAM_STR and were corrupted (regression guard).
     */
    #[Test]
    public function executeBindsBinaryParamWithoutCorruption(): void
    {
        $this->connection->execute('ALTER TABLE items ADD COLUMN payload BLOB');

        // Non-UTF-8 bytes, including a NUL, that PARAM_STR would mangle.
        $bytes = "\x00\xff\x01\x80binary\x00data\xfe";

        $stmt = $this->connection->prepare('UPDATE items SET payload = :payload WHERE name = :name');
        $affected = $stmt->executeAffecting(['payload' => Param::binary($bytes), 'name' => 'alpha']);

        self::assertSame(1, $affected);

        $result = $this->connection->query(
            'SELECT payload FROM items WHERE name = :name',
            ['name' => 'alpha'],
        );

        self::assertSame($bytes, $result->rows[0]->getString('payload'));
    }

    #[Test]
    public function fluentBindingsMergeWithExecuteBindings(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE active = :active AND name = :name');

        $result = $stmt->bind('active', 1)->execute(['name' => 'alpha']);

        self::assertCount(1, $result->rows);
    }

    #[Test]
    public function executeBindingsOverrideFluentBindings(): void
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE name = :name');

        $result = $stmt->bind('name', 'alpha')->execute(['name' => 'beta']);

        self::assertCount(1, $result->rows);
        self::assertSame('beta', $result->rows[0]->getString('name'));
    }

    #[Test]
    public function executeAffectingWithNoMatchReturnsZero(): void
    {
        $stmt = $this->connection->prepare('UPDATE items SET active = :active WHERE name = :name');

        $affected = $stmt->executeAffecting(['active' => 0, 'name' => 'nonexistent']);

        self::assertSame(0, $affected);
    }

    #[Test]
    public function executeAffectingWithFluentBindings(): void
    {
        $stmt = $this->connection->prepare('DELETE FROM items WHERE name = :name');

        $affected = $stmt->bind('name', 'alpha')->executeAffecting();

        self::assertSame(1, $affected);
    }

    /**
     * The streaming variant yields rows lazily so a query over a
     * million-row table doesn't materialise the whole result set in
     * PHP memory.
     */
    #[Test]
    public function executeStreamingYieldsRowsLazily(): void
    {
        $stmt = $this->connection->prepare('SELECT name FROM items ORDER BY id ASC');

        $names = [];
        foreach ($stmt->executeStreaming() as $row) {
            self::assertIsString($row['name']);
            $names[] = $row['name'];
        }

        self::assertSame(['alpha', 'beta'], $names);
    }

    /**
     * Closing the cursor partway through must not corrupt subsequent
     * statements on the same connection.
     */
    #[Test]
    public function executeStreamingPartialDrainDoesNotPinResources(): void
    {
        $stmt = $this->connection->prepare('SELECT name FROM items ORDER BY id ASC');

        $generator = $stmt->executeStreaming();
        $first = $generator->current();
        self::assertSame('alpha', $first['name']);

        // Bail out without draining the rest.
        unset($generator);

        // Subsequent query on the same connection must still work.
        $stmt2 = $this->connection->prepare('SELECT COUNT(*) AS n FROM items');
        $count = $stmt2->execute()->rows[0]->getInt('n');
        self::assertSame(2, $count);
    }
}
