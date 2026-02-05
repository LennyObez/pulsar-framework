<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\ListResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

#[CoversClass(ListResourceHandler::class)]
final class ListResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private DataResourceInterface&Stub $resource;
    private ListResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->resource = $this->createStub(DataResourceInterface::class);
        $config = AdminConfig::fromArray(['enabled' => true]);

        $this->registry->method('get')->willReturn($this->resource);

        $this->handler = new ListResourceHandler(
            $this->registry,
            $this->query,
            new FieldVisibilityFilter(),
            $config,
        );
    }

    private function handlerWithMocks(
        ResourceQueryInterface|null $query = null,
    ): ListResourceHandler {
        return new ListResourceHandler(
            $this->registry,
            $query ?? $this->query,
            new FieldVisibilityFilter(),
            AdminConfig::fromArray(['enabled' => true]),
        );
    }

    #[Test]
    public function listsResourcesWithPagination(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');
        $this->query->method('list')->willReturn([
            'data' => [
                ['id' => '1', 'name' => 'Alice'],
                ['id' => '2', 'name' => 'Bob'],
            ],
            'total' => 10,
            'page' => 1,
            'per_page' => 25,
        ]);

        $request = new ListResourceRequest(
            resourceName: 'users',
            page: 1,
            perPage: 25,
        );

        $result = $this->handler->execute($request);

        self::assertSame(10, $result->total);
        self::assertSame(1, $result->page);
        self::assertSame(25, $result->perPage);
        self::assertSame(1, $result->totalPages);
    }

    #[Test]
    public function calculatesTotalPagesCorrectly(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');
        $this->query->method('list')->willReturn([
            'data' => [['id' => '1']],
            'total' => 53,
            'page' => 1,
            'per_page' => 10,
        ]);

        $request = new ListResourceRequest(
            resourceName: 'users',
            page: 1,
            perPage: 10,
        );

        $result = $this->handler->execute($request);

        self::assertSame(53, $result->total);
        self::assertSame(6, $result->totalPages);
    }

    #[Test]
    public function clampsPerPageToMaxPerPage(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');

        /** @var ResourceQueryInterface&MockObject $query */
        $query = $this->createMock(ResourceQueryInterface::class);
        $query->expects($this->once())
            ->method('list')
            ->with(
                $this->resource,
                [],
                [],
                1,
                100, // maxPerPage from default config
            )
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 100,
            ]);

        $handler = $this->handlerWithMocks(query: $query);

        $request = new ListResourceRequest(
            resourceName: 'users',
            perPage: 999,
        );

        $handler->execute($request);
    }

    #[Test]
    public function clampsPerPageToMinimumOfOne(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');

        /** @var ResourceQueryInterface&MockObject $query */
        $query = $this->createMock(ResourceQueryInterface::class);
        $query->expects($this->once())
            ->method('list')
            ->with(
                $this->resource,
                [],
                [],
                1,
                1,
            )
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 1,
            ]);

        $handler = $this->handlerWithMocks(query: $query);

        $request = new ListResourceRequest(
            resourceName: 'users',
            perPage: -5,
        );

        $handler->execute($request);
    }

    #[Test]
    public function passesFiltersAndSortToQuery(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');

        $filters = ['status' => 'active'];
        $sort = ['name' => 'asc'];

        /** @var ResourceQueryInterface&MockObject $query */
        $query = $this->createMock(ResourceQueryInterface::class);
        $query->expects($this->once())
            ->method('list')
            ->with(
                $this->resource,
                $filters,
                $sort,
                2,
                25,
            )
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 2,
                'per_page' => 25,
            ]);

        $handler = $this->handlerWithMocks(query: $query);

        $request = new ListResourceRequest(
            resourceName: 'users',
            filters: $filters,
            sort: $sort,
            page: 2,
            perPage: 25,
        );

        $handler->execute($request);
    }

    #[Test]
    public function returnsTotalPagesOneWhenEmpty(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');
        $this->query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 25,
        ]);

        $request = new ListResourceRequest(resourceName: 'users');

        $result = $this->handler->execute($request);

        self::assertSame(0, $result->total);
        self::assertSame(1, $result->totalPages);
        self::assertSame([], $result->data);
    }

    #[Test]
    public function clampsPageToMinimumOfOne(): void
    {
        $this->resource->method('fields')->willReturn([]);
        $this->resource->method('primaryKey')->willReturn('id');

        /** @var ResourceQueryInterface&MockObject $query */
        $query = $this->createMock(ResourceQueryInterface::class);
        $query->expects($this->once())
            ->method('list')
            ->with(
                $this->resource,
                [],
                [],
                1,
                25,
            )
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 25,
            ]);

        $handler = $this->handlerWithMocks(query: $query);

        $request = new ListResourceRequest(
            resourceName: 'users',
            page: -1,
        );

        $handler->execute($request);
    }
}
