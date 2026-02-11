<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceQuery;

#[CoversClass(OrmResourceQuery::class)]
final class OrmResourceQueryTest extends TestCase
{
    /** @var ConnectionInterface&Stub */
    private ConnectionInterface $connection;
    private OrmResourceQuery $query;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->query = new OrmResourceQuery($this->connection);
    }

    private function makeResource(): DataResourceInterface
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn('id');
        $resource->method('defaultSortDirection')->willReturn('desc');
        $resource->method('fields')->willReturn([
            new FieldDefinition('id', FieldType::Integer, 'ID', sortable: true, filterable: true, searchable: false),
            new FieldDefinition('name', FieldType::String, 'Name', sortable: true, filterable: false, searchable: true),
            new FieldDefinition('email', FieldType::Email, 'Email', sortable: true, filterable: true, searchable: true),
            new FieldDefinition('status', FieldType::String, 'Status', sortable: false, filterable: true, searchable: false),
        ]);

        return $resource;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function makeResult(array $rows = []): Result
    {
        $rowObjects = array_map(
            static fn(array $data): Row => new Row($data),
            $rows,
        );

        return new Result(array_values($rowObjects));
    }

    #[Test]
    public function findReturnsRowData(): void
    {
        $resultSet = $this->makeResult([['id' => 1, 'name' => 'John', 'email' => 'john@test.com']]);
        $this->connection->method('query')->willReturn($resultSet);

        $result = $this->query->find($this->makeResource(), '1');

        self::assertNotNull($result);
        self::assertSame(1, $result['id']);
        self::assertSame('John', $result['name']);
    }

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        $resultSet = $this->makeResult([]);
        $this->connection->method('query')->willReturn($resultSet);

        $result = $this->query->find($this->makeResource(), '999');

        self::assertNull($result);
    }

    #[Test]
    public function countReturnsTotal(): void
    {
        $resultSet = $this->makeResult([['cnt' => 42]]);
        $this->connection->method('query')->willReturn($resultSet);

        $result = $this->query->count($this->makeResource());

        self::assertSame(42, $result);
    }

    #[Test]
    public function countReturnsZeroForEmptyResult(): void
    {
        $resultSet = $this->makeResult([]);
        $this->connection->method('query')->willReturn($resultSet);

        $result = $this->query->count($this->makeResource());

        self::assertSame(0, $result);
    }

    #[Test]
    public function searchReturnsEmptyWhenNoSearchableFields(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('settings');
        $resource->method('fields')->willReturn([
            new FieldDefinition('key', FieldType::String, 'Key', searchable: false),
        ]);

        $result = $this->query->search($resource, 'query');

        self::assertSame([], $result);
    }

    #[Test]
    public function searchReturnsMatchingRows(): void
    {
        $resultSet = $this->makeResult([
            ['id' => 1, 'name' => 'John', 'email' => 'john@test.com'],
        ]);
        $this->connection->method('query')->willReturn($resultSet);

        $result = $this->query->search($this->makeResource(), 'john');

        self::assertCount(1, $result);
        self::assertSame('John', $result[0]['name']);
    }

    #[Test]
    public function listReturnsPaginatedResults(): void
    {
        $countResultSet = $this->makeResult([['cnt' => 50]]);
        $dataResultSet = $this->makeResult([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ]);
        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResultSet, $dataResultSet);

        $result = $this->query->list($this->makeResource(), page: 1, perPage: 2);

        self::assertSame(50, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(2, $result['per_page']);
        self::assertCount(2, $result['data']);
    }

    #[Test]
    public function listEnforcesPageMinimumOfOne(): void
    {
        $countResultSet = $this->makeResult([['cnt' => 10]]);
        $dataResultSet = $this->makeResult([]);
        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResultSet, $dataResultSet);

        $result = $this->query->list($this->makeResource(), page: -5, perPage: 10);

        // Negative page numbers should be treated as page 1
        self::assertSame(1, $result['page']);
    }
}
