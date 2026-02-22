<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\CursorPaginator;
use Pulsar\Api\Pagination\PaginationRequest;

#[CoversClass(CursorPaginator::class)]
final class CursorPaginatorTest extends TestCase
{
    private CursorPaginator $paginator;

    protected function setUp(): void
    {
        $this->paginator = new CursorPaginator();
    }

    #[Test]
    public function paginatesWithHasMoreDetection(): void
    {
        // Provide perPage+1 items to signal hasMore
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
            ['id' => 4, 'name' => 'Extra'], // extra item signals hasMore
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $this->paginator->paginate($items, -1, $request);

        self::assertCount(3, $result->items);
        self::assertTrue($result->hasMore);
        self::assertNotNull($result->nextCursor);
    }

    #[Test]
    public function noPagesLeftWhenItemsEqualOrLessThanPerPage(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $this->paginator->paginate($items, -1, $request);

        self::assertCount(2, $result->items);
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    #[Test]
    public function cursorEncodesAndDecodes(): void
    {
        $items = [
            ['id' => 10, 'name' => 'Alice'],
            ['id' => 11, 'name' => 'Bob'],
            ['id' => 12, 'name' => 'Charlie'],
            ['id' => 13, 'name' => 'Extra'],
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $this->paginator->paginate($items, -1, $request);
        $decoded = CursorPaginator::decodeCursor($result->nextCursor);

        self::assertNotNull($decoded);
        self::assertArrayHasKey('p', $decoded);
        self::assertSame(12, $decoded['p']); // last item in the page
        self::assertArrayHasKey('n', $decoded);
        self::assertSame(3, $decoded['n']);
    }

    #[Test]
    public function decodeCursorReturnsNullForNull(): void
    {
        self::assertNull(CursorPaginator::decodeCursor(null));
    }

    #[Test]
    public function decodeCursorReturnsNullForEmptyString(): void
    {
        self::assertNull(CursorPaginator::decodeCursor(''));
    }

    #[Test]
    public function decodeCursorReturnsNullForInvalidBase64(): void
    {
        self::assertNull(CursorPaginator::decodeCursor('!!!invalid!!!'));
    }

    #[Test]
    public function decodeCursorReturnsNullForNonJsonPayload(): void
    {
        $cursor = base64_encode('not-json');

        self::assertNull(CursorPaginator::decodeCursor($cursor));
    }

    #[Test]
    public function preservesCursorFromRequest(): void
    {
        $items = [
            ['id' => 5, 'name' => 'Eve'],
            ['id' => 6, 'name' => 'Frank'],
        ];
        $cursor = base64_encode(json_encode(['p' => 4, 'n' => 2], JSON_THROW_ON_ERROR));
        $request = new PaginationRequest(perPage: 3, cursor: $cursor);

        $result = $this->paginator->paginate($items, -1, $request);

        self::assertSame($cursor, $result->cursor);
    }

    #[Test]
    public function generatesLinksWithBaseUrl(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
            ['id' => 4, 'name' => 'Extra'],
        ];
        $request = new PaginationRequest(perPage: 3);

        $result = $this->paginator->paginate($items, -1, $request, '/api/users');

        self::assertNotNull($result->links->first);
        self::assertStringContainsString('per_page=3', $result->links->first);
        self::assertNotNull($result->links->next);
        self::assertStringContainsString('after=', $result->links->next);
    }

    #[Test]
    public function noLinksWithoutBaseUrl(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $request = new PaginationRequest(perPage: 3);

        $result = $this->paginator->paginate($items, -1, $request);

        self::assertNull($result->links->first);
        self::assertNull($result->links->next);
    }

    #[Test]
    public function emptyItemSet(): void
    {
        $request = new PaginationRequest(perPage: 10);

        $result = $this->paginator->paginate([], -1, $request);

        self::assertSame([], $result->items);
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    #[Test]
    public function preservesTotalWhenProvided(): void
    {
        $items = [['id' => 1]];
        $request = new PaginationRequest(perPage: 10);

        $result = $this->paginator->paginate($items, 42, $request);

        self::assertSame(42, $result->total);
    }

    #[Test]
    public function totalIsNullWhenUnknown(): void
    {
        $items = [['id' => 1]];
        $request = new PaginationRequest(perPage: 10);

        $result = $this->paginator->paginate($items, -1, $request);

        self::assertNull($result->total);
    }
}
