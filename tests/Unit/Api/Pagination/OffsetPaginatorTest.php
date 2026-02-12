<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\OffsetPaginator;
use Pulsar\Api\Pagination\PaginationRequest;

#[CoversClass(OffsetPaginator::class)]
final class OffsetPaginatorTest extends TestCase
{
    private OffsetPaginator $paginator;

    protected function setUp(): void
    {
        $this->paginator = new OffsetPaginator();
    }

    #[Test]
    public function slicesItemsForFirstPage(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 3, page: 1);

        $result = $this->paginator->paginate($items, 10, $request);

        self::assertSame([1, 2, 3], $result->items);
        self::assertSame(10, $result->total);
        self::assertTrue($result->hasMore);
        self::assertSame(3, $result->perPage);
        self::assertSame(1, $result->currentPage);
    }

    #[Test]
    public function slicesItemsForMiddlePage(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 3, page: 2);

        $result = $this->paginator->paginate($items, 10, $request);

        self::assertSame([4, 5, 6], $result->items);
        self::assertSame(2, $result->currentPage);
        self::assertTrue($result->hasMore);
    }

    #[Test]
    public function lastPageHasNoMore(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 3, page: 4);

        $result = $this->paginator->paginate($items, 10, $request);

        // page 4 offset=9, only item 10
        self::assertSame([10], $result->items);
        self::assertFalse($result->hasMore);
        self::assertSame(4, $result->lastPage);
    }

    #[Test]
    public function calculatesLastPageCorrectly(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 3, page: 1);

        $result = $this->paginator->paginate($items, 10, $request);

        // 10 items / 3 per page = 4 pages (ceiling)
        self::assertSame(4, $result->lastPage);
    }

    #[Test]
    public function exactPageBoundary(): void
    {
        $items = range(1, 9);
        $request = new PaginationRequest(perPage: 3, page: 3);

        $result = $this->paginator->paginate($items, 9, $request);

        self::assertSame([7, 8, 9], $result->items);
        self::assertFalse($result->hasMore);
        self::assertSame(3, $result->lastPage);
    }

    #[Test]
    public function generatesLinksWithBaseUrl(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 3, page: 2);

        $result = $this->paginator->paginate($items, 10, $request, '/api/users');

        self::assertNotNull($result->links->first);
        self::assertStringContainsString('page=1', $result->links->first);
        self::assertNotNull($result->links->next);
        self::assertStringContainsString('page=3', $result->links->next);
        self::assertNotNull($result->links->prev);
        self::assertStringContainsString('page=1', $result->links->prev);
        self::assertNotNull($result->links->last);
        self::assertStringContainsString('page=4', $result->links->last);
    }

    #[Test]
    public function firstPageHasNoPrevLink(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 5, page: 1);

        $result = $this->paginator->paginate($items, 10, $request, '/api/users');

        self::assertNull($result->links->prev);
        self::assertNotNull($result->links->next);
    }

    #[Test]
    public function lastPageHasNoNextLink(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 5, page: 2);

        $result = $this->paginator->paginate($items, 10, $request, '/api/users');

        self::assertNull($result->links->next);
        self::assertNotNull($result->links->prev);
    }

    #[Test]
    public function noLinksWithoutBaseUrl(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 5, page: 1);

        $result = $this->paginator->paginate($items, 10, $request);

        self::assertNull($result->links->first);
        self::assertNull($result->links->next);
    }

    #[Test]
    public function handlesUnknownTotal(): void
    {
        $items = range(1, 5);
        $request = new PaginationRequest(perPage: 3, page: 1);

        $result = $this->paginator->paginate($items, -1, $request);

        self::assertNull($result->total);
        self::assertNull($result->lastPage);
        // has more because count(items) > perPage
        self::assertTrue($result->hasMore);
    }

    #[Test]
    public function emptyItemSet(): void
    {
        $request = new PaginationRequest(perPage: 10, page: 1);

        $result = $this->paginator->paginate([], 0, $request);

        self::assertSame([], $result->items);
        self::assertSame(0, $result->total);
        self::assertFalse($result->hasMore);
        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function baseUrlWithExistingQueryParams(): void
    {
        $items = range(1, 10);
        $request = new PaginationRequest(perPage: 5, page: 1);

        $result = $this->paginator->paginate($items, 10, $request, '/api/users?filter=active');

        self::assertNotNull($result->links->first);
        self::assertStringContainsString('&page=1', $result->links->first);
    }
}
