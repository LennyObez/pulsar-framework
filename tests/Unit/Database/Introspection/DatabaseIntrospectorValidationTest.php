<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Introspection;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\ColumnInfo;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\PdoConnection;

#[CoversClass(DatabaseIntrospector::class)]
#[CoversClass(ColumnInfo::class)]
final class DatabaseIntrospectorValidationTest extends TestCase
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

        $this->connection->execute('CREATE TABLE valid_table (id INTEGER PRIMARY KEY)');

        $this->introspector = new DatabaseIntrospector($this->connection);
    }

    #[Test]
    public function columnsThrowsForEmptyTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid database identifier');

        $this->introspector->columns('');
    }

    #[Test]
    public function columnsThrowsForTableNameStartingWithDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid database identifier');

        $this->introspector->columns('123table');
    }

    #[Test]
    public function columnsThrowsForTableNameWithSpecialCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid database identifier');

        $this->introspector->columns('table; DROP TABLE users--');
    }

    #[Test]
    public function columnsThrowsForTableNameWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid database identifier');

        $this->introspector->columns('my table');
    }

    #[Test]
    #[DataProvider('sqlInjectionPayloads')]
    public function columnsRejectsSqlInjectionPayloads(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid database identifier');

        $this->introspector->columns($payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlInjectionPayloads(): iterable
    {
        yield 'semicolon injection' => ['users; DROP TABLE users'];
        yield 'comment injection' => ['users--'];
        yield 'quote escape' => ["users' OR '1'='1"];
        yield 'parentheses' => ['users)'];
        yield 'union select' => ['users UNION SELECT'];
        yield 'backtick' => ['`users`'];
        yield 'dot notation' => ['schema.users'];
        yield 'newline' => ["users\n"];
        yield 'null byte' => ["users\0"];
    }

    #[Test]
    public function columnsReturnsEmptyForTableNameExceeding128Characters(): void
    {
        $longName = str_repeat('a', 129);

        $columns = $this->introspector->columns($longName);

        self::assertSame([], $columns);
    }

    #[Test]
    public function columnsAcceptsValidTableNameWithUnderscore(): void
    {
        $columns = $this->introspector->columns('valid_table');

        self::assertCount(1, $columns);
        self::assertSame('id', $columns[0]->name);
    }

    #[Test]
    public function columnsAcceptsTableNameStartingWithUnderscore(): void
    {
        $this->connection->execute('CREATE TABLE _private_table (id INTEGER PRIMARY KEY)');

        $columns = $this->introspector->columns('_private_table');

        self::assertCount(1, $columns);
    }

    #[Test]
    public function primaryKeyDelegatesToColumns(): void
    {
        $pk = $this->introspector->primaryKey('valid_table');

        self::assertSame('id', $pk);
    }

    #[Test]
    public function primaryKeyReturnsNullForNonexistentTable(): void
    {
        $pk = $this->introspector->primaryKey('nonexistent_table');

        self::assertNull($pk);
    }

    #[Test]
    public function primaryKeyRejectsInvalidIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid database identifier');

        $this->introspector->primaryKey('table; DROP TABLE users--');
    }
}
