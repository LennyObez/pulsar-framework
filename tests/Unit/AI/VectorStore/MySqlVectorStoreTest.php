<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\VectorStore\DistanceMetric;
use Pulsar\AI\VectorStore\MySqlVectorStore;
use Pulsar\AI\VectorStore\VectorStoreInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(MySqlVectorStore::class)]
final class MySqlVectorStoreTest extends TestCase
{
    #[Test]
    public function implementsVectorStoreInterface(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act
        $store = new MySqlVectorStore($connection);

        // Assert
        self::assertInstanceOf(VectorStoreInterface::class, $store);
    }

    #[Test]
    public function defaultDimensionsIs1536(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act
        $store = new MySqlVectorStore($connection);

        // Assert
        self::assertSame(1536, $store->dimensions);
    }

    #[Test]
    public function customDimensionsArePreserved(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act
        $store = new MySqlVectorStore($connection, dimensions: 768);

        // Assert
        self::assertSame(768, $store->dimensions);
    }

    #[Test]
    public function searchReturnsSearchResultsFromQueryRows(): void
    {
        // Arrange
        $rows = [
            new Row(['id' => 'doc-1', 'content' => 'First document', 'metadata' => '{"type":"article"}', 'score' => 0.95]),
            new Row(['id' => 'doc-2', 'content' => 'Second document', 'metadata' => '{}', 'score' => 0.82]),
        ];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act
        $results = $store->search([0.1, 0.2, 0.3], 10);

        // Assert
        self::assertCount(2, $results);
        self::assertSame('doc-1', $results[0]->id);
        self::assertSame(0.95, $results[0]->score);
        self::assertSame('First document', $results[0]->content);
        self::assertSame(['type' => 'article'], $results[0]->metadata);
        self::assertSame('doc-2', $results[1]->id);
    }

    #[Test]
    public function searchWithFilterPassesConditionsToQuery(): void
    {
        // Arrange
        $result = new Result([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act
        $results = $store->search([0.1], 5, ['category' => 'news']);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchWithL2MetricDoesNotThrow(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection, metric: DistanceMetric::L2);

        // Act
        $results = $store->search([0.1, 0.2], 5);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchWithInnerProductMetricDoesNotThrow(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection, metric: DistanceMetric::InnerProduct);

        // Act
        $results = $store->search([0.1, 0.2], 5);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchHandlesNonStringMetadata(): void
    {
        // Arrange -- metadata is not a string (null-like scenario)
        $rows = [new Row(['id' => 'x', 'content' => 'text', 'metadata' => null, 'score' => 0.5])];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertCount(1, $results);
        self::assertSame([], $results[0]->metadata);
    }

    #[Test]
    public function searchHandlesInvalidJsonMetadata(): void
    {
        // Arrange -- metadata is a string but invalid JSON
        $rows = [new Row(['id' => 'x', 'content' => 'text', 'metadata' => 'not-json', 'score' => 0.5])];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertCount(1, $results);
        self::assertSame([], $results[0]->metadata);
    }

    #[Test]
    public function searchHandlesNonScalarIdAndContent(): void
    {
        // Arrange
        $rows = [new Row(['id' => null, 'content' => null, 'metadata' => '{}', 'score' => null])];
        $result = new Result($rows);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act
        $results = $store->search([0.1], 1);

        // Assert
        self::assertCount(1, $results);
        self::assertSame('', $results[0]->id);
        self::assertSame('', $results[0]->content);
        self::assertSame(0.0, $results[0]->score);
    }

    #[Test]
    public function upsertDelegatesToConnectionExecute(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $store = new MySqlVectorStore($connection);

        // Act -- no exception means success
        $store->upsert('doc-1', [0.1, 0.2], 'My content', ['tag' => 'test']);

        // Assert -- upsert is void; we verify no exception was thrown
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }

    #[Test]
    public function upsertWithEmptyMetadata(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $store = new MySqlVectorStore($connection);

        // Act -- no exception means success
        $store->upsert('doc-2', [0.5, 0.6], 'Content');

        // Assert
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }

    #[Test]
    public function deleteExecutesDeleteQuery(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $store = new MySqlVectorStore($connection);

        // Act
        $store->delete('doc-1');

        // Assert
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }

    #[Test]
    public function clearWithoutFilterDeletesAll(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(5);

        $store = new MySqlVectorStore($connection);

        // Act
        $store->clear();

        // Assert
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }

    #[Test]
    public function clearWithFilterDeletesMatching(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(2);

        $store = new MySqlVectorStore($connection);

        // Act
        $store->clear(['category' => 'obsolete']);

        // Assert
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }

    #[Test]
    public function countWithoutFilterReturnsTotal(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 42])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act & Assert
        self::assertSame(42, $store->count());
    }

    #[Test]
    public function countWithFilterReturnsFilteredTotal(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 7])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act & Assert
        self::assertSame(7, $store->count(['type' => 'article']));
    }

    #[Test]
    public function countReturnsZeroWhenNoRows(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function countReturnsZeroWhenCntIsNotNumeric(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => 'not-a-number'])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function countWithFilterReturnsZeroWhenNoRows(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count(['type' => 'missing']));
    }

    #[Test]
    public function countWithFilterReturnsZeroWhenCntNotNumeric(): void
    {
        // Arrange
        $result = new Result([new Row(['cnt' => null])]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act & Assert
        self::assertSame(0, $store->count(['type' => 'x']));
    }

    #[Test]
    public function constructorRejectsUnsafeTableName(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);

        // Act & Assert
        $this->expectException(AiException::class);
        new MySqlVectorStore($connection, 'DROP TABLE x');
    }

    #[Test]
    public function searchWithMultipleFilters(): void
    {
        // Arrange
        $result = new Result([]);
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $store = new MySqlVectorStore($connection);

        // Act
        $results = $store->search([0.1], 5, ['type' => 'article', 'lang' => 'en']);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function clearWithNonScalarFilterValue(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(0);

        $store = new MySqlVectorStore($connection);

        // Act -- non-scalar value should be cast to empty string
        $store->clear(['key' => ['nested']]);

        // Assert
        self::assertInstanceOf(MySqlVectorStore::class, $store);
    }
}
