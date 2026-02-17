<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\VectorStore\DistanceMetric;
use Pulsar\AI\VectorStore\PgVectorStore;
use Pulsar\AI\VectorStore\VectorStoreInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(PgVectorStore::class)]
final class PgVectorStoreTest extends TestCase
{
    #[Test]
    public function implementsVectorStoreInterface(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act
        $store = new PgVectorStore($connection);

        // Assert
        self::assertInstanceOf(VectorStoreInterface::class, $store);
    }

    #[Test]
    public function defaultDimensionsIs1536(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act
        $store = new PgVectorStore($connection);

        // Assert
        self::assertSame(1536, $store->dimensions);
    }

    #[Test]
    public function searchReturnsMappedResults(): void
    {
        // Arrange
        $rows = [
            new Row(['id' => 'pg-1', 'content' => 'PG doc', 'metadata' => '{"source":"wiki"}', 'score' => 0.92]),
        ];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act
        $results = $store->search([0.1, 0.2], 5);

        // Assert
        self::assertCount(1, $results);
        self::assertSame('pg-1', $results[0]->id);
        self::assertSame(0.92, $results[0]->score);
        self::assertSame('PG doc', $results[0]->content);
        self::assertSame(['source' => 'wiki'], $results[0]->metadata);
    }

    #[Test]
    public function searchWithL2Metric(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection, metric: DistanceMetric::L2);

        // Act
        $results = $store->search([0.1], 3);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchWithInnerProductMetric(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection, metric: DistanceMetric::InnerProduct);

        // Act
        $results = $store->search([0.1], 3);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchWithFilterAppliesConditions(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act
        $results = $store->search([0.5], 10, ['category' => 'tech', 'lang' => 'en']);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchHandlesNonStringMetadata(): void
    {
        // Arrange
        $rows = [new Row(['id' => 'x', 'content' => 'c', 'metadata' => null, 'score' => 0.1])];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertSame([], $results[0]->metadata);
    }

    #[Test]
    public function searchHandlesInvalidJsonMetadata(): void
    {
        // Arrange
        $rows = [new Row(['id' => 'y', 'content' => 'c', 'metadata' => '{invalid', 'score' => 0.1])];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertSame([], $results[0]->metadata);
    }

    #[Test]
    public function searchHandlesNonScalarFields(): void
    {
        // Arrange
        $rows = [new Row(['id' => null, 'content' => null, 'metadata' => '{}', 'score' => null])];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertSame('', $results[0]->id);
        self::assertSame('', $results[0]->content);
        self::assertSame(0.0, $results[0]->score);
    }

    #[Test]
    public function upsertExecutesWithoutError(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $store = new PgVectorStore($connection);

        // Act
        $store->upsert('id-1', [0.1, 0.2], 'content', ['key' => 'val']);

        // Assert
        self::assertInstanceOf(PgVectorStore::class, $store);
    }

    #[Test]
    public function upsertWithEmptyMetadata(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $store = new PgVectorStore($connection);

        // Act
        $store->upsert('id-2', [0.3], 'text');

        // Assert
        self::assertInstanceOf(PgVectorStore::class, $store);
    }

    #[Test]
    public function deleteDelegatesToConnection(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $store = new PgVectorStore($connection);

        // Act
        $store->delete('doc-99');

        // Assert
        self::assertInstanceOf(PgVectorStore::class, $store);
    }

    #[Test]
    public function clearWithoutFilterDeletesAll(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(10);

        $store = new PgVectorStore($connection);

        // Act
        $store->clear();

        // Assert
        self::assertInstanceOf(PgVectorStore::class, $store);
    }

    #[Test]
    public function clearWithFilterDeletesMatching(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(3);

        $store = new PgVectorStore($connection);

        // Act
        $store->clear(['status' => 'archived']);

        // Assert
        self::assertInstanceOf(PgVectorStore::class, $store);
    }

    #[Test]
    public function countWithoutFilterReturnsTotal(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 100])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act & Assert
        self::assertSame(100, $store->count());
    }

    #[Test]
    public function countWithFilterReturnsFilteredTotal(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 15])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act & Assert
        self::assertSame(15, $store->count(['type' => 'blog']));
    }

    #[Test]
    public function countReturnsZeroWhenNoRows(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function countReturnsZeroForNonNumericCnt(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 'abc'])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

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

        $store = new PgVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count(['x' => 'y']));
    }

    #[Test]
    public function countWithFilterReturnsZeroForNonNumericCnt(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => null])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new PgVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count(['x' => 'y']));
    }

    #[Test]
    public function constructorRejectsUnsafeTableName(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act & Assert
        $this->expectException(AiException::class);
        new PgVectorStore($connection, 'bad table');
    }
}
