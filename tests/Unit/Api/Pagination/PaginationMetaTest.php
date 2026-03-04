<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationMeta;
use Pulsar\Api\Pagination\PaginationResult;

#[CoversClass(PaginationMeta::class)]
final class PaginationMetaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $meta = new PaginationMeta(perPage: 25, hasMore: true);

        $array = $meta->toArray();

        self::assertSame(25, $array['per_page']);
        self::assertTrue($array['has_more']);
    }

    #[Test]
    public function toArrayOmitsNullOptionalFields(): void
    {
        $meta = new PaginationMeta(perPage: 10, hasMore: false);

        $array = $meta->toArray();

        self::assertArrayNotHasKey('total', $array);
        self::assertArrayNotHasKey('current_page', $array);
        self::assertArrayNotHasKey('last_page', $array);
        self::assertArrayNotHasKey('cursor', $array);
        self::assertArrayNotHasKey('next_cursor', $array);
        self::assertArrayNotHasKey('prev_cursor', $array);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $meta = new PaginationMeta(
            perPage: 20,
            hasMore: true,
            total: 200,
            currentPage: 5,
            lastPage: 10,
            cursor: 'c1',
            nextCursor: 'c2',
            prevCursor: 'c0',
        );

        $array = $meta->toArray();

        self::assertSame(200, $array['total']);
        self::assertSame(5, $array['current_page']);
        self::assertSame(10, $array['last_page']);
        self::assertSame('c1', $array['cursor']);
        self::assertSame('c2', $array['next_cursor']);
        self::assertSame('c0', $array['prev_cursor']);
    }

    #[Test]
    public function fromResultTransfersAllFields(): void
    {
        /** @var PaginationResult<mixed> $result */
        $result = new PaginationResult(
            items: ['a', 'b'],
            total: 50,
            hasMore: true,
            perPage: 15,
            cursor: 'cursor_pos',
            nextCursor: 'next_pos',
            prevCursor: 'prev_pos',
            currentPage: 2,
            lastPage: 4,
        );

        $meta = PaginationMeta::fromResult($result);

        self::assertSame(15, $meta->perPage);
        self::assertTrue($meta->hasMore);
        self::assertSame(50, $meta->total);
        self::assertSame(2, $meta->currentPage);
        self::assertSame(4, $meta->lastPage);
        self::assertSame('cursor_pos', $meta->cursor);
        self::assertSame('next_pos', $meta->nextCursor);
        self::assertSame('prev_pos', $meta->prevCursor);
    }

    #[Test]
    public function fromResultWithMinimalData(): void
    {
        /** @var PaginationResult<mixed> $result */
        $result = new PaginationResult(
            items: [],
            total: null,
            hasMore: false,
            perPage: 10,
        );

        $meta = PaginationMeta::fromResult($result);

        self::assertSame(10, $meta->perPage);
        self::assertFalse($meta->hasMore);
        self::assertNull($meta->total);
        self::assertNull($meta->currentPage);
    }
}
