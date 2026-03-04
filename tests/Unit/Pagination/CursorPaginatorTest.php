<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Pagination;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Pagination\CursorPaginator;

final class CursorPaginatorTest extends TestCase
{
    #[Test]
    public function detectsMorePagesWhenFetchedItemsExceedPerPage(): void
    {
        // Fetch perPage + 1 items to detect next page
        $items = ['a', 'b', 'c', 'd', 'e', 'f'];
        $paginator = new CursorPaginator($items, perPage: 5, cursor: null);

        self::assertTrue($paginator->hasMore);
        self::assertCount(5, $paginator->items);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $paginator->items);
    }

    #[Test]
    public function noMorePagesWhenFetchedItemsMatchPerPage(): void
    {
        $items = ['a', 'b', 'c'];
        $paginator = new CursorPaginator($items, perPage: 5, cursor: null);

        self::assertFalse($paginator->hasMore);
        self::assertCount(3, $paginator->items);
    }

    #[Test]
    public function nextCursorReturnsNullWhenNoMorePages(): void
    {
        $paginator = new CursorPaginator(['a'], perPage: 5, cursor: null);

        $next = $paginator->nextCursor(static fn(string $item): string => $item);

        self::assertNull($next);
    }

    #[Test]
    public function nextCursorReturnsEncodedCursorWhenMoreExist(): void
    {
        // 4 items with perPage=3 means hasMore=true
        $paginator = new CursorPaginator(['a', 'b', 'c', 'd'], perPage: 3, cursor: null);

        $next = $paginator->nextCursor(static fn(string $item): string => $item);

        self::assertNotNull($next);

        $decoded = CursorPaginator::decodeCursor($next);
        self::assertNotNull($decoded);
        self::assertSame('id', $decoded['column']);
        self::assertSame('c', $decoded['value']);
    }

    #[Test]
    public function encodeCursorProducesDecodableCursor(): void
    {
        $cursor = CursorPaginator::encodeCursor('created_at', '2026-01-01');
        $decoded = CursorPaginator::decodeCursor($cursor);

        self::assertNotNull($decoded);
        self::assertSame('created_at', $decoded['column']);
        self::assertSame('2026-01-01', $decoded['value']);
    }

    #[Test]
    public function decodeCursorReturnsNullForInvalidBase64(): void
    {
        self::assertNull(CursorPaginator::decodeCursor('not-valid-base64!!!'));
    }

    #[Test]
    public function decodeCursorReturnsNullForInvalidJson(): void
    {
        $encoded = base64_encode('not json');

        self::assertNull(CursorPaginator::decodeCursor($encoded));
    }

    #[Test]
    public function decodeCursorReturnsNullForMissingFields(): void
    {
        $encoded = base64_encode(json_encode(['x' => 'y'], JSON_THROW_ON_ERROR));

        self::assertNull(CursorPaginator::decodeCursor($encoded));
    }

    #[Test]
    public function toArrayIncludesItemsAndMeta(): void
    {
        $paginator = new CursorPaginator(['a', 'b', 'c'], perPage: 5, cursor: null);

        $array = $paginator->toArray(static fn(string $item): string => $item);

        self::assertSame(['a', 'b', 'c'], $array['items']);
        self::assertSame(5, $array['meta']['per_page']);
        self::assertFalse($array['meta']['has_more']);
        self::assertNull($array['meta']['next_cursor']);
    }

    #[Test]
    public function toArrayIncludesNextCursorWhenMoreExist(): void
    {
        $paginator = new CursorPaginator(['a', 'b', 'c', 'd'], perPage: 3, cursor: null);

        $array = $paginator->toArray(static fn(string $item): string => $item);

        self::assertTrue($array['meta']['has_more']);
        self::assertNotNull($array['meta']['next_cursor']);
    }

    #[Test]
    public function emptyResultSetHasNoMore(): void
    {
        $paginator = new CursorPaginator([], perPage: 10, cursor: null);

        self::assertFalse($paginator->hasMore);
        self::assertSame([], $paginator->items);
    }

    #[Test]
    public function customCursorColumnIsPreserved(): void
    {
        $paginator = new CursorPaginator(['x', 'y'], perPage: 1, cursor: null, cursorColumn: 'created_at');
        $next = $paginator->nextCursor(static fn(string $item): string => $item);

        self::assertNotNull($next);
        $decoded = CursorPaginator::decodeCursor($next);
        self::assertNotNull($decoded);
        self::assertSame('created_at', $decoded['column']);
    }
}
