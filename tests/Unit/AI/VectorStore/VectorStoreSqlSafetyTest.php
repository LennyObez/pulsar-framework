<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\VectorStore\MySqlVectorStore;
use Pulsar\AI\VectorStore\PgVectorStore;
use Pulsar\AI\VectorStore\SqliteVecStore;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;

/**
 * Tests SQL identifier safety (CWE-89) across all vector store implementations.
 *
 * Validates that:
 * - Table names are validated at construction time
 * - Filter keys in metadata queries are validated before interpolation
 * - Only alphanumeric + underscore identifiers are accepted
 */
#[CoversClass(MySqlVectorStore::class)]
#[CoversClass(PgVectorStore::class)]
#[CoversClass(SqliteVecStore::class)]
final class VectorStoreSqlSafetyTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  Table name validation at construction
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('unsafeTableNames')]
    public function mysqlRejectsUnsafeTableName(string $tableName): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        new MySqlVectorStore($connection, $tableName);
    }

    #[Test]
    #[DataProvider('unsafeTableNames')]
    public function pgVectorRejectsUnsafeTableName(string $tableName): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        new PgVectorStore($connection, $tableName);
    }

    #[Test]
    #[DataProvider('unsafeTableNames')]
    public function sqliteVecRejectsUnsafeTableName(string $tableName): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        new SqliteVecStore($connection, $tableName);
    }

    #[Test]
    #[DataProvider('safeTableNames')]
    public function mysqlAcceptsSafeTableName(string $tableName): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act — no exception expected
        $store = new MySqlVectorStore($connection, $tableName);

        // Assert
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }

    #[Test]
    #[DataProvider('safeTableNames')]
    public function pgVectorAcceptsSafeTableName(string $tableName): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act
        $store = new PgVectorStore($connection, $tableName);

        // Assert
        self::assertInstanceOf(PgVectorStore::class, $store);
    }

    #[Test]
    #[DataProvider('safeTableNames')]
    public function sqliteVecAcceptsSafeTableName(string $tableName): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act
        $store = new SqliteVecStore($connection, $tableName);

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    // -----------------------------------------------------------------------
    //  Filter key validation in search/clear/count
    // -----------------------------------------------------------------------

    #[Test]
    public function mysqlSearchRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new MySqlVectorStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->search([0.1, 0.2], 10, ["key'; DROP TABLE x--" => 'value']);
    }

    #[Test]
    public function mysqlClearRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new MySqlVectorStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->clear(["') OR 1=1--" => 'value']);
    }

    #[Test]
    public function mysqlCountRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new MySqlVectorStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->count(['UNION SELECT' => 'value']);
    }

    #[Test]
    public function pgVectorSearchRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new PgVectorStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->search([0.1, 0.2], 10, ["key'; DROP TABLE x--" => 'value']);
    }

    #[Test]
    public function pgVectorClearRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new PgVectorStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->clear(["') OR 1=1--" => 'value']);
    }

    #[Test]
    public function pgVectorCountRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new PgVectorStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->count(['UNION SELECT' => 'value']);
    }

    #[Test]
    public function sqliteVecClearRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new SqliteVecStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->clear(["') OR 1=1--" => 'value']);
    }

    #[Test]
    public function sqliteVecCountRejectsUnsafeFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new SqliteVecStore($connection, 'vector_documents');

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SQL identifier.*invalid characters/');

        $store->count(['UNION SELECT' => 'value']);
    }

    #[Test]
    public function mysqlSearchAcceptsSafeFilterKey(): void
    {
        // Arrange
        $emptyResult = $this->createStub(Result::class);
        $emptyResult->method('map')->willReturn([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($emptyResult);

        $store = new MySqlVectorStore($connection, 'vector_documents');

        // Act — safe key, no exception expected
        $results = $store->search([0.1, 0.2], 10, ['valid_key' => 'value']);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function pgVectorSearchAcceptsSafeFilterKey(): void
    {
        // Arrange
        $emptyResult = $this->createStub(Result::class);
        $emptyResult->method('map')->willReturn([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($emptyResult);

        $store = new PgVectorStore($connection, 'vector_documents');

        // Act
        $results = $store->search([0.1, 0.2], 10, ['valid_key' => 'value']);

        // Assert
        self::assertSame([], $results);
    }

    // -----------------------------------------------------------------------
    //  Exception message includes the offending identifier
    // -----------------------------------------------------------------------

    #[Test]
    public function exceptionMessageContainsOffendingTableName(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();

        // Act & Assert
        try {
            new MySqlVectorStore($connection, 'DROP TABLE users');
            self::fail('Expected AiException was not thrown');
        } catch (AiException $e) {
            self::assertStringContainsString('DROP TABLE users', $e->getMessage());
        }
    }

    #[Test]
    public function exceptionMessageContainsOffendingFilterKey(): void
    {
        // Arrange
        $connection = $this->createConnectionStub();
        $store = new MySqlVectorStore($connection, 'vector_documents');

        // Act & Assert
        try {
            $store->count(['bad.key' => 'v']);
            self::fail('Expected AiException was not thrown');
        } catch (AiException $e) {
            self::assertStringContainsString('bad.key', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    //  Data providers
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeTableNames(): iterable
    {
        yield 'SQL injection with semicolon' => ['users; DROP TABLE x'];
        yield 'SQL injection with quotes' => ["users' --"];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'space in name' => ['vector documents'];
        yield 'hyphenated name' => ['vector-documents'];
        yield 'starts with number' => ['1table'];
        yield 'empty string' => [''];
        yield 'dot notation' => ['schema.table'];
        yield 'backtick escape' => ['`users`'];
        yield 'parentheses' => ['tbl()'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeTableNames(): iterable
    {
        yield 'simple name' => ['vector_documents'];
        yield 'underscore prefix' => ['_private_vectors'];
        yield 'camelCase' => ['vectorDocuments'];
        yield 'PascalCase' => ['VectorDocuments'];
        yield 'with numbers' => ['vectors_v2'];
        yield 'all underscore' => ['_'];
        yield 'uppercase' => ['VECTORS'];
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    /**
     * @return ConnectionInterface&Stub
     */
    private function createConnectionStub(): ConnectionInterface&Stub
    {
        return $this->createStub(ConnectionInterface::class);
    }
}
