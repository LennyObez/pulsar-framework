<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Pagination;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Pagination\PaginationResult;

final class PaginationResultTest extends TestCase
{
    #[Test]
    public function constructSetsLastPageFromTotalAndPerPage(): void
    {
        $result = new PaginationResult(['a', 'b'], total: 25, perPage: 10, currentPage: 1);

        self::assertSame(3, $result->lastPage);
    }

    #[Test]
    public function lastPageIsOneWhenTotalIsZero(): void
    {
        $result = new PaginationResult([], total: 0, perPage: 10, currentPage: 1);

        self::assertSame(1, $result->lastPage);
    }

    #[Test]
    public function lastPageIsOneWhenPerPageIsZero(): void
    {
        $result = new PaginationResult([], total: 100, perPage: 0, currentPage: 1);

        self::assertSame(1, $result->lastPage);
    }

    #[Test]
    public function hasMorePagesReturnsTrueWhenNotOnLastPage(): void
    {
        $result = new PaginationResult(['a'], total: 30, perPage: 10, currentPage: 2);

        self::assertTrue($result->hasMorePages());
    }

    #[Test]
    public function hasMorePagesReturnsFalseOnLastPage(): void
    {
        $result = new PaginationResult(['a'], total: 30, perPage: 10, currentPage: 3);

        self::assertFalse($result->hasMorePages());
    }

    #[Test]
    public function isFirstPageReturnsTrueForPageOne(): void
    {
        $result = new PaginationResult(['a'], total: 30, perPage: 10, currentPage: 1);

        self::assertTrue($result->isFirstPage());
    }

    #[Test]
    public function isFirstPageReturnsFalseForLaterPages(): void
    {
        $result = new PaginationResult(['a'], total: 30, perPage: 10, currentPage: 2);

        self::assertFalse($result->isFirstPage());
    }

    #[Test]
    public function isLastPageReturnsTrueForFinalPage(): void
    {
        $result = new PaginationResult(['a'], total: 30, perPage: 10, currentPage: 3);

        self::assertTrue($result->isLastPage());
    }

    #[Test]
    public function countReturnsItemCount(): void
    {
        $result = new PaginationResult(['a', 'b', 'c'], total: 30, perPage: 10, currentPage: 1);

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function isEmptyReturnsTrueForNoItems(): void
    {
        $result = new PaginationResult([], total: 0, perPage: 10, currentPage: 1);

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWithItems(): void
    {
        $result = new PaginationResult(['a'], total: 1, perPage: 10, currentPage: 1);

        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function offsetCalculatesCorrectly(): void
    {
        $result = new PaginationResult([], total: 100, perPage: 15, currentPage: 3);

        self::assertSame(30, $result->offset());
    }

    #[Test]
    public function offsetIsZeroForFirstPage(): void
    {
        $result = new PaginationResult([], total: 100, perPage: 15, currentPage: 1);

        self::assertSame(0, $result->offset());
    }

    #[Test]
    public function toArrayReturnsItemsAndMeta(): void
    {
        $result = new PaginationResult(['x', 'y'], total: 25, perPage: 10, currentPage: 2);

        $array = $result->toArray();

        self::assertSame(['x', 'y'], $array['items']);
        self::assertSame(2, $array['meta']['current_page']);
        self::assertSame(10, $array['meta']['per_page']);
        self::assertSame(25, $array['meta']['total']);
        self::assertSame(3, $array['meta']['last_page']);
        self::assertTrue($array['meta']['has_more']);
    }

    #[Test]
    public function toArrayShowsNoMoreOnLastPage(): void
    {
        $result = new PaginationResult(['z'], total: 3, perPage: 10, currentPage: 1);

        self::assertFalse($result->toArray()['meta']['has_more']);
    }

    #[Test]
    #[DataProvider('lastPageProvider')]
    public function lastPageCeiling(int $total, int $perPage, int $expected): void
    {
        $result = new PaginationResult([], total: $total, perPage: $perPage, currentPage: 1);

        self::assertSame($expected, $result->lastPage);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function lastPageProvider(): iterable
    {
        yield 'exact division' => [30, 10, 3];
        yield 'remainder rounds up' => [31, 10, 4];
        yield 'single page' => [5, 10, 1];
        yield 'one item per page' => [5, 1, 5];
    }
}
