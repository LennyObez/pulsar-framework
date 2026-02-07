<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\KeysetPaginator;
use Pulsar\Api\Pagination\PaginationRequest;

#[CoversClass(KeysetPaginator::class)]
final class KeysetPaginatorTest extends TestCase
{
    #[Test]
    public function paginatesWithHasMoreDetection(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
            ['id' => 4, 'name' => 'Extra'],
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $paginator->paginate($items, -1, $request);

        self::assertCount(3, $result->items);
        self::assertTrue($result->hasMore);
        self::assertNotNull($result->nextCursor);
    }

    #[Test]
    public function noMorePagesWhenItemsFitSinglePage(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $request = new PaginationRequest(perPage: 5);

        $result = $paginator->paginate($items, -1, $request);

        self::assertCount(2, $result->items);
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    #[Test]
    public function cursorRoundTrip(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $items = [
            ['id' => 10, 'name' => 'Alice'],
            ['id' => 20, 'name' => 'Bob'],
            ['id' => 30, 'name' => 'Charlie'],
            ['id' => 40, 'name' => 'Extra'],
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $paginator->paginate($items, -1, $request);
        $decoded = KeysetPaginator::decodeCursor($result->nextCursor);

        self::assertNotNull($decoded);
        self::assertSame('id', $decoded['key']);
        self::assertSame(30, $decoded['value']); // last item's id on the page
    }

    #[Test]
    public function decodeCursorReturnsNullForNull(): void
    {
        self::assertNull(KeysetPaginator::decodeCursor(null));
    }

    #[Test]
    public function decodeCursorReturnsNullForEmpty(): void
    {
        self::assertNull(KeysetPaginator::decodeCursor(''));
    }

    #[Test]
    public function decodeCursorReturnsNullForInvalidBase64(): void
    {
        self::assertNull(KeysetPaginator::decodeCursor('!!!'));
    }

    #[Test]
    public function decodeCursorReturnsNullForMissingKeys(): void
    {
        $cursor = base64_encode(json_encode(['x' => 1], JSON_THROW_ON_ERROR));

        self::assertNull(KeysetPaginator::decodeCursor($cursor));
    }

    #[Test]
    public function decodeCursorReturnsNullForNonStringKey(): void
    {
        $cursor = base64_encode(json_encode(['k' => 123, 'v' => 'abc'], JSON_THROW_ON_ERROR));

        self::assertNull(KeysetPaginator::decodeCursor($cursor));
    }

    #[Test]
    public function usesSortKeyForCursorEncoding(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'created_at');
        $items = [
            ['id' => 1, 'created_at' => '2024-01-01'],
            ['id' => 2, 'created_at' => '2024-01-02'],
            ['id' => 3, 'created_at' => '2024-01-03'],
            ['id' => 4, 'created_at' => '2024-01-04'],
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $paginator->paginate($items, -1, $request);
        $decoded = KeysetPaginator::decodeCursor($result->nextCursor);

        self::assertNotNull($decoded);
        self::assertSame('created_at', $decoded['key']);
        self::assertSame('2024-01-03', $decoded['value']);
    }

    #[Test]
    public function prevCursorGeneratedWhenNavigatingWithCursor(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $items = [
            ['id' => 5, 'name' => 'Eve'],
            ['id' => 6, 'name' => 'Frank'],
        ];
        $existingCursor = base64_encode(json_encode(['k' => 'id', 'v' => 4], JSON_THROW_ON_ERROR));
        $request = new PaginationRequest(perPage: 5, cursor: $existingCursor);

        $result = $paginator->paginate($items, -1, $request);

        self::assertNotNull($result->prevCursor);
        $decoded = KeysetPaginator::decodeCursor($result->prevCursor);
        self::assertNotNull($decoded);
        self::assertSame('id', $decoded['key']);
        self::assertSame(5, $decoded['value']); // first item's id
    }

    #[Test]
    public function noPrevCursorOnFirstPage(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $items = [
            ['id' => 1, 'name' => 'Alice'],
        ];
        $request = new PaginationRequest(perPage: 5);

        $result = $paginator->paginate($items, -1, $request);

        self::assertNull($result->prevCursor);
    }

    #[Test]
    public function generatesLinksWithBaseUrl(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $items = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4], // extra
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $paginator->paginate($items, -1, $request, '/api/users');

        self::assertNotNull($result->links->first);
        self::assertStringContainsString('per_page=3', $result->links->first);
        self::assertNotNull($result->links->next);
        self::assertStringContainsString('after=', $result->links->next);
    }

    #[Test]
    public function emptyItems(): void
    {
        $paginator = new KeysetPaginator(sortKey: 'id');
        $request = new PaginationRequest(perPage: 10);

        $result = $paginator->paginate([], -1, $request);

        self::assertSame([], $result->items);
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }
}
