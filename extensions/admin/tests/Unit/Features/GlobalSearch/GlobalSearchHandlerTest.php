<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\GlobalSearch;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
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

final class GlobalSearchHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private FieldVisibilityFilter $visibilityFilter;
    private GlobalSearchHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->visibilityFilter = new FieldVisibilityFilter();
        $this->handler = new GlobalSearchHandler($this->registry, $this->query, $this->visibilityFilter);
    }

    #[Test]
    public function execute_returns_empty_for_blank_query(): void
    {
        $request = new GlobalSearchRequest(query: '');
        $result = $this->handler->execute($request);

        self::assertInstanceOf(GlobalSearchResult::class, $result);
        self::assertSame([], $result->results);
        self::assertSame(0, $result->totalMatches);
    }

    #[Test]
    public function execute_searches_across_multiple_resources(): void
    {
        $usersResource = $this->createResourceStub();
        $ordersResource = $this->createResourceStub();

        $this->registry->method('all')->willReturn([
            'users' => $usersResource,
            'orders' => $ordersResource,
        ]);

        $this->query->method('search')->willReturnOnConsecutiveCalls(
            [['id' => '1', 'name' => 'Jane']],
            [['id' => 'ord-1', 'customer' => 'Jane']],
        );

        $request = new GlobalSearchRequest(query: 'Jane', limitPerResource: 5);
        $result = $this->handler->execute($request);

        self::assertSame(2, $result->totalMatches);
        self::assertArrayHasKey('users', $result->results);
        self::assertArrayHasKey('orders', $result->results);
    }

    #[Test]
    public function execute_skips_resources_with_no_matches(): void
    {
        $usersResource = $this->createResourceStub();
        $ordersResource = $this->createResourceStub();

        $this->registry->method('all')->willReturn([
            'users' => $usersResource,
            'orders' => $ordersResource,
        ]);

        $this->query->method('search')->willReturnOnConsecutiveCalls(
            [['id' => '1', 'name' => 'Match']],
            [],
        );

        $request = new GlobalSearchRequest(query: 'Match');
        $result = $this->handler->execute($request);

        self::assertSame(1, $result->totalMatches);
        self::assertArrayHasKey('users', $result->results);
        self::assertArrayNotHasKey('orders', $result->results);
    }

    #[Test]
    public function execute_returns_zero_matches_when_no_resources_match(): void
    {
        $resource = $this->createResourceStub();
        $this->registry->method('all')->willReturn(['items' => $resource]);
        $this->query->method('search')->willReturn([]);

        $request = new GlobalSearchRequest(query: 'nonexistent');
        $result = $this->handler->execute($request);

        self::assertSame(0, $result->totalMatches);
        self::assertSame([], $result->results);
    }

    private function createResourceStub(): DataResourceInterface&Stub
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Text, label: 'ID'),
        ]);

        return $resource;
    }
}
