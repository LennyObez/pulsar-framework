<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Pagination;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Pagination\PageLink;
use Pulsar\Pagination\PaginationResult;
use Pulsar\Pagination\Paginator;

use function count;

final class PaginatorTest extends TestCase
{
    #[Test]
    public function fromCollectionSlicesCorrectly(): void
    {
        $all = range(1, 50);
        $paginator = Paginator::fromCollection($all, currentPage: 2, perPage: 10);

        self::assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20], $paginator->items);
        self::assertSame(50, $paginator->total);
        self::assertSame(5, $paginator->lastPage);
    }

    #[Test]
    public function fromCollectionClampsNegativePage(): void
    {
        $paginator = Paginator::fromCollection(['a', 'b', 'c'], currentPage: -1, perPage: 2);

        self::assertSame(['a', 'b'], $paginator->items);
        self::assertSame(1, $paginator->currentPage);
    }

    #[Test]
    public function hasMorePagesWhenNotOnLastPage(): void
    {
        $paginator = new Paginator(['a'], total: 20, currentPage: 1, perPage: 10);

        self::assertTrue($paginator->hasMorePages());
    }

    #[Test]
    public function noMorePagesOnLastPage(): void
    {
        $paginator = new Paginator(['a'], total: 20, currentPage: 2, perPage: 10);

        self::assertFalse($paginator->hasMorePages());
    }

    #[Test]
    public function hasPreviousPageReturnsFalseOnFirstPage(): void
    {
        $paginator = new Paginator([], total: 10, currentPage: 1, perPage: 5);

        self::assertFalse($paginator->hasPreviousPage());
    }

    #[Test]
    public function hasPreviousPageReturnsTrueOnSecondPage(): void
    {
        $paginator = new Paginator([], total: 10, currentPage: 2, perPage: 5);

        self::assertTrue($paginator->hasPreviousPage());
    }

    #[Test]
    public function previousPageReturnsNullOnFirstPage(): void
    {
        $paginator = new Paginator([], total: 10, currentPage: 1, perPage: 5);

        self::assertNull($paginator->previousPage());
    }

    #[Test]
    public function previousPageReturnsCorrectNumber(): void
    {
        $paginator = new Paginator([], total: 30, currentPage: 3, perPage: 10);

        self::assertSame(2, $paginator->previousPage());
    }

    #[Test]
    public function nextPageReturnsNullOnLastPage(): void
    {
        $paginator = new Paginator([], total: 20, currentPage: 2, perPage: 10);

        self::assertNull($paginator->nextPage());
    }

    #[Test]
    public function nextPageReturnsCorrectNumber(): void
    {
        $paginator = new Paginator([], total: 30, currentPage: 1, perPage: 10);

        self::assertSame(2, $paginator->nextPage());
    }

    #[Test]
    public function linksContainsPreviousAndNextArrows(): void
    {
        $paginator = new Paginator([], total: 50, currentPage: 3, perPage: 10);
        $links = $paginator->links();

        $first = $links[0];
        $last = $links[count($links) - 1];

        self::assertSame('&laquo;', $first->label);
        self::assertSame('&raquo;', $last->label);
    }

    #[Test]
    public function linksShowsActivePageCorrectly(): void
    {
        $paginator = new Paginator([], total: 50, currentPage: 3, perPage: 10);
        $links = $paginator->links();

        $activeLinks = array_filter($links, static fn(PageLink $l): bool => $l->isActive);

        self::assertCount(1, $activeLinks);
        $active = array_values($activeLinks)[0];
        self::assertSame(3, $active->page);
    }

    #[Test]
    public function linksPreviousArrowDisabledOnFirstPage(): void
    {
        $paginator = new Paginator([], total: 50, currentPage: 1, perPage: 10);
        $links = $paginator->links();

        self::assertTrue($links[0]->isDisabled);
    }

    #[Test]
    public function linksNextArrowDisabledOnLastPage(): void
    {
        $paginator = new Paginator([], total: 50, currentPage: 5, perPage: 10);
        $links = $paginator->links();

        self::assertTrue($links[count($links) - 1]->isDisabled);
    }

    #[Test]
    public function linksIncludesEllipsisForLargePageCounts(): void
    {
        $paginator = new Paginator([], total: 200, currentPage: 10, perPage: 10);
        $links = $paginator->links(onEachSide: 2);

        $ellipses = array_filter($links, static fn(PageLink $l): bool => $l->isEllipsis);

        self::assertGreaterThanOrEqual(1, count($ellipses));
    }

    #[Test]
    public function linksSinglePageReturnsOneEntry(): void
    {
        $paginator = new Paginator(['a'], total: 1, currentPage: 1, perPage: 10);
        $links = $paginator->links();

        self::assertCount(1, $links);
        self::assertTrue($links[0]->isActive);
    }

    #[Test]
    public function toResultReturnsPaginationResultDto(): void
    {
        $paginator = new Paginator(['x'], total: 5, currentPage: 1, perPage: 3);
        $result = $paginator->toResult();

        self::assertInstanceOf(PaginationResult::class, $result);
        self::assertSame(['x'], $result->items);
        self::assertSame(5, $result->total);
    }

    #[Test]
    public function fromCollectionBeyondLastPageReturnsEmpty(): void
    {
        $paginator = Paginator::fromCollection([1, 2, 3], currentPage: 100, perPage: 5);

        self::assertSame([], $paginator->items);
        self::assertSame(100, $paginator->currentPage);
    }
}
