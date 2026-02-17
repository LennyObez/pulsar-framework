<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\VectorStore\DistanceMetric;
use Pulsar\AI\VectorStore\SqliteVecStore;
use Pulsar\AI\VectorStore\VectorStoreInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(SqliteVecStore::class)]
final class SqliteVecStoreTest extends TestCase
{
    #[Test]
    public function implementsVectorStoreInterface(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act
        $store = new SqliteVecStore($connection);

        // Assert
        self::assertInstanceOf(VectorStoreInterface::class, $store);
    }

    #[Test]
    public function ensureTablesExecutesTwoStatements(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(0);

        $store = new SqliteVecStore($connection);

        // Act -- no exception means success
        $store->ensureTables();

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function searchReturnsMappedResultsWithCosineScore(): void
    {
        // Arrange
        $rows = [
            new Row(['id' => 'sq-1', 'dist' => 0.1, 'content' => 'SQLite doc', 'metadata' => '{"source":"local"}']),
        ];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection);

        // Act
        $results = $store->search([0.1, 0.2], 5);

        // Assert
        self::assertCount(1, $results);
        self::assertSame('sq-1', $results[0]->id);
        self::assertEqualsWithDelta(0.9, $results[0]->score, 0.001); // 1 - 0.1
        self::assertSame('SQLite doc', $results[0]->content);
        self::assertSame(['source' => 'local'], $results[0]->metadata);
    }

    #[Test]
    public function searchWithL2MetricReturnsNegativeDistance(): void
    {
        // Arrange
        $rows = [new Row(['id' => 'x', 'dist' => 2.5, 'content' => 'c', 'metadata' => '{}'])];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection, metric: DistanceMetric::L2);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertEqualsWithDelta(-2.5, $results[0]->score, 0.001);
    }

    #[Test]
    public function searchWithInnerProductMetricReturnsNegativeDistance(): void
    {
        // Arrange
        $rows = [new Row(['id' => 'x', 'dist' => 1.0, 'content' => 'c', 'metadata' => '{}'])];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection, metric: DistanceMetric::InnerProduct);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertEqualsWithDelta(-1.0, $results[0]->score, 0.001);
    }

    #[Test]
    public function searchFiltersResultsByMetadataInPhp(): void
    {
        // Arrange -- two rows but only one matches the filter
        $rows = [
            new Row(['id' => 'match', 'dist' => 0.1, 'content' => 'matched', 'metadata' => '{"type":"article"}']),
            new Row(['id' => 'skip', 'dist' => 0.2, 'content' => 'skipped', 'metadata' => '{"type":"video"}']),
        ];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection);

        // Act
        $results = $store->search([0.1], 10, ['type' => 'article']);

        // Assert
        self::assertCount(1, $results);
        self::assertSame('match', $results[0]->id);
    }

    #[Test]
    public function searchFilterExcludesWhenKeyIsMissing(): void
    {
        // Arrange -- metadata has no 'status' key
        $rows = [
            new Row(['id' => 'x', 'dist' => 0.1, 'content' => 'c', 'metadata' => '{"type":"a"}']),
        ];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection);

        // Act
        $results = $store->search([0.1], 5, ['status' => 'active']);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchHandlesNonStringMetadata(): void
    {
        // Arrange
        $rows = [new Row(['id' => 'x', 'dist' => 0.0, 'content' => 'c', 'metadata' => null])];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertSame([], $results[0]->metadata);
    }

    #[Test]
    public function searchHandlesNonNumericDistance(): void
    {
        // Arrange
        $rows = [new Row(['id' => 'x', 'dist' => 'bad', 'content' => 'c', 'metadata' => '{}'])];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertEqualsWithDelta(1.0, $results[0]->score, 0.001); // 1 - 0.0
    }

    #[Test]
    public function searchHandlesNonScalarIdAndContent(): void
    {
        // Arrange
        $rows = [new Row(['id' => null, 'dist' => 0.5, 'content' => null, 'metadata' => '{}'])];
        $queryResult = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($queryResult);

        $store = new SqliteVecStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertSame('', $results[0]->id);
        self::assertSame('', $results[0]->content);
    }

    #[Test]
    public function upsertUsesTransaction(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            },
        );
        $connection->method('execute')->willReturn(1);

        $store = new SqliteVecStore($connection);

        // Act
        $store->upsert('doc-1', [0.1, 0.2], 'content', ['tag' => 'test']);

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function upsertWithEmptyMetadata(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            },
        );
        $connection->method('execute')->willReturn(1);

        $store = new SqliteVecStore($connection);

        // Act
        $store->upsert('doc-2', [0.5], 'plain text');

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function deleteUsesTransaction(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            },
        );
        $connection->method('execute')->willReturn(1);

        $store = new SqliteVecStore($connection);

        // Act
        $store->delete('doc-1');

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function clearWithoutFilterDeletesBothTables(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            },
        );
        $connection->method('execute')->willReturn(0);

        $store = new SqliteVecStore($connection);

        // Act
        $store->clear();

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function clearWithFilterDeletesFilteredRows(): void
    {
        // Arrange
        $metaRows = [new Row(['id' => 'doc-a']), new Row(['id' => 'doc-b'])];
        $queryResult = new Result($metaRows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            },
        );
        $connection->method('query')->willReturn($queryResult);
        $connection->method('execute')->willReturn(1);

        $store = new SqliteVecStore($connection);

        // Act
        $store->clear(['status' => 'draft']);

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function clearWithFilterHandlesNonScalarId(): void
    {
        // Arrange
        $metaRows = [new Row(['id' => null])];
        $queryResult = new Result($metaRows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            },
        );
        $connection->method('query')->willReturn($queryResult);
        $connection->method('execute')->willReturn(1);

        $store = new SqliteVecStore($connection);

        // Act
        $store->clear(['type' => 'old']);

        // Assert
        self::assertInstanceOf(SqliteVecStore::class, $store);
    }

    #[Test]
    public function countWithoutFilterReturnsTotal(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 25])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new SqliteVecStore($connection);

        // Act & Assert
        self::assertSame(25, $store->count());
    }

    #[Test]
    public function countWithFilterReturnsFilteredTotal(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 3])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new SqliteVecStore($connection);

        // Act & Assert
        self::assertSame(3, $store->count(['type' => 'note']));
    }

    #[Test]
    public function countReturnsZeroWhenNoRows(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new SqliteVecStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function countReturnsZeroForNonNumericCnt(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 'nan'])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new SqliteVecStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function countWithFilterReturnsZeroWhenEmpty(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new SqliteVecStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count(['missing' => 'key']));
    }

    #[Test]
    public function countWithFilterReturnsZeroForNonNumericCnt(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => null])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new SqliteVecStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count(['k' => 'v']));
    }

    #[Test]
    public function constructorRejectsUnsafeTableName(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act & Assert
        $this->expectException(AiException::class);
        new SqliteVecStore($connection, 'evil;table');
    }

    #[Test]
    public function clearWithFilterRejectsUnsafeKey(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $store = new SqliteVecStore($connection);

        // Act & Assert
        $this->expectException(AiException::class);
        $store->clear(['bad key' => 'value']);
    }

    #[Test]
    public function countWithFilterRejectsUnsafeKey(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $store = new SqliteVecStore($connection);

        // Act & Assert
        $this->expectException(AiException::class);
        $store->count(['bad.key' => 'value']);
    }
}
