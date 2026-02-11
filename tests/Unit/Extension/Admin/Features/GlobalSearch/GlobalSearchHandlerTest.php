<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\GlobalSearch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchRequest;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchResult;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

#[CoversClass(GlobalSearchHandler::class)]
#[CoversClass(GlobalSearchRequest::class)]
#[CoversClass(GlobalSearchResult::class)]
final class GlobalSearchHandlerTest extends TestCase
{
    #[Test]
    public function emptyQueryReturnsEmptyResult(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $query = $this->createStub(ResourceQueryInterface::class);
        $filter = new FieldVisibilityFilter();

        $handler = new GlobalSearchHandler($registry, $query, $filter);
        $result = $handler->execute(new GlobalSearchRequest(query: ''));

        self::assertSame([], $result->results);
        self::assertSame(0, $result->totalMatches);
    }

    #[Test]
    public function searchAcrossMultipleResources(): void
    {
        $resource1 = $this->createResourceStub('users');
        $resource2 = $this->createResourceStub('orders');

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn(['users' => $resource1, 'orders' => $resource2]);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('search')->willReturnCallback(
            static fn(DataResourceInterface $r): array => match ($r->name()) {
                'users' => [['id' => '1', 'name' => 'John']],
                'orders' => [['id' => '10', 'name' => 'Order A'], ['id' => '11', 'name' => 'Order B']],
                default => [],
            },
        );

        $filter = new FieldVisibilityFilter();
        $handler = new GlobalSearchHandler($registry, $query, $filter);

        $result = $handler->execute(new GlobalSearchRequest(query: 'test', limitPerResource: 10));

        self::assertCount(2, $result->results);
        self::assertArrayHasKey('users', $result->results);
        self::assertArrayHasKey('orders', $result->results);
        self::assertSame(3, $result->totalMatches);
    }

    #[Test]
    public function skipsResourcesWithNoMatches(): void
    {
        $resource = $this->createResourceStub('products');

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn(['products' => $resource]);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('search')->willReturn([]);

        $filter = new FieldVisibilityFilter();
        $handler = new GlobalSearchHandler($registry, $query, $filter);

        $result = $handler->execute(new GlobalSearchRequest(query: 'nonexistent'));

        self::assertSame([], $result->results);
        self::assertSame(0, $result->totalMatches);
    }

    #[Test]
    public function requestDefaultLimitPerResource(): void
    {
        $request = new GlobalSearchRequest(query: 'search term');

        self::assertSame('search term', $request->query);
        self::assertSame(5, $request->limitPerResource);
    }

    private function createResourceStub(string $name): DataResourceInterface
    {
        $field = new FieldDefinition(
            name: 'name',
            type: FieldType::Text,
            label: 'Name',
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn($name);
        $resource->method('fields')->willReturn([$field]);
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('exportableFields')->willReturn(['name']);

        return $resource;
    }
}
