<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationResult;

#[CoversClass(PaginationResult::class)]
final class PaginationResultTest extends TestCase
{
    #[Test]
    public function countReturnsItemCount(): void
    {
        $result = new PaginationResult(
            items: ['a', 'b', 'c'],
            total: 100,
            hasMore: true,
            perPage: 10,
        );

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function isEmptyReturnsTrueForNoItems(): void
    {
        $result = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 10,
        );

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenItemsExist(): void
    {
        $result = new PaginationResult(
            items: ['x'],
            total: 1,
            hasMore: false,
            perPage: 10,
        );

        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function metaToArrayIncludesRequiredFields(): void
    {
        $result = new PaginationResult(
            items: ['a'],
            total: null,
            hasMore: true,
            perPage: 25,
        );

        $meta = $result->metaToArray();

        self::assertSame(25, $meta['per_page']);
        self::assertTrue($meta['has_more']);
        self::assertArrayNotHasKey('total', $meta);
    }

    #[Test]
    public function metaToArrayIncludesTotalWhenPresent(): void
    {
        $result = new PaginationResult(
            items: [],
            total: 42,
            hasMore: false,
            perPage: 10,
        );

        $meta = $result->metaToArray();

        self::assertSame(42, $meta['total']);
    }

    #[Test]
    public function metaToArrayIncludesOffsetPaginationFields(): void
    {
        $result = new PaginationResult(
            items: [],
            total: 100,
            hasMore: true,
            perPage: 10,
            currentPage: 3,
            lastPage: 10,
        );

        $meta = $result->metaToArray();

        self::assertSame(3, $meta['current_page']);
        self::assertSame(10, $meta['last_page']);
    }

    #[Test]
    public function metaToArrayIncludesCursorPaginationFields(): void
    {
        $result = new PaginationResult(
            items: [],
            total: null,
            hasMore: true,
            perPage: 20,
            cursor: 'abc123',
            nextCursor: 'def456',
            prevCursor: 'ghi789',
        );

        $meta = $result->metaToArray();

        self::assertSame('abc123', $meta['cursor']);
        self::assertSame('def456', $meta['next_cursor']);
        self::assertSame('ghi789', $meta['prev_cursor']);
    }

    #[Test]
    public function metaToArrayIncludesLinksWhenPresent(): void
    {
        $links = new PaginationLinks(
            first: '/api/items?page=1',
            next: '/api/items?page=2',
        );

        $result = new PaginationResult(
            items: [],
            total: null,
            hasMore: true,
            perPage: 10,
            links: $links,
        );

        $meta = $result->metaToArray();

        self::assertArrayHasKey('links', $meta);
        self::assertIsArray($meta['links']);
    }

    #[Test]
    public function metaToArrayOmitsLinksWhenEmpty(): void
    {
        $result = new PaginationResult(
            items: [],
            total: null,
            hasMore: false,
            perPage: 10,
        );

        $meta = $result->metaToArray();

        self::assertArrayNotHasKey('links', $meta);
    }
}
