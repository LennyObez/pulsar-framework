<?php

declare(strict_types=1);

namespace Pulsar\Tests\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Pagination\PageLink;
use Pulsar\Pagination\Paginator;

use function array_fill;
use function array_filter;
use function ceil;
use function count;
use function max;
use function min;
use function reset;

/**
 * Property-based tests for Paginator.
 *
 * Verifies mathematical invariants that must hold for ANY positive perPage and total:
 * - Page count = ceil(total / perPage)
 * - Items on any page <= perPage
 * - First page is always 1
 * - Navigation methods are consistent with current position
 */
#[CoversClass(Paginator::class)]
#[CoversClass(PageLink::class)]
#[Group('property')]
final class PaginatorPropertyTest extends TestCase
{
    /**
     * For any positive perPage and total, page count equals ceil(total / perPage).
     */
    #[Test]
    #[DataProvider('perPageAndTotalCombinations')]
    public function pageCountIsCeilOfTotalDividedByPerPage(int $total, int $perPage): void
    {
        $paginator = new Paginator(
            items: [],
            total: $total,
            currentPage: 1,
            perPage: $perPage,
        );

        $expectedLastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        self::assertSame(
            $expectedLastPage,
            $paginator->lastPage,
            "For total={$total}, perPage={$perPage}: lastPage should be " . $expectedLastPage,
        );
    }

    /**
     * fromCollection slices correctly for any page.
     */
    #[Test]
    #[DataProvider('collectionSlicingScenarios')]
    public function fromCollectionSlicesCorrectly(int $totalItems, int $perPage, int $currentPage): void
    {
        $allItems = array_fill(0, $totalItems, 'item');
        $paginator = Paginator::fromCollection($allItems, $currentPage, $perPage);

        $effectivePage = max(1, $currentPage);
        $expectedLastPage = $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 1;
        $expectedOffset = ($effectivePage - 1) * $perPage;
        $expectedCount = min($perPage, max(0, $totalItems - $expectedOffset));

        self::assertCount(
            $expectedCount,
            $paginator->items,
            "Page {$effectivePage} of {$totalItems} items (perPage={$perPage}) should have {$expectedCount} items",
        );
        self::assertSame($totalItems, $paginator->total);
        self::assertSame($expectedLastPage, $paginator->lastPage);
    }

    /**
     * hasMorePages is true only when currentPage < lastPage.
     */
    #[Test]
    #[DataProvider('navigationScenarios')]
    public function hasMorePagesIsConsistent(int $total, int $perPage, int $currentPage): void
    {
        $paginator = new Paginator([], $total, $currentPage, $perPage);

        if ($currentPage < $paginator->lastPage) {
            self::assertTrue($paginator->hasMorePages(), "Page {$currentPage} of {$paginator->lastPage} should have more pages");
        } else {
            self::assertFalse($paginator->hasMorePages(), "Page {$currentPage} of {$paginator->lastPage} should not have more pages");
        }
    }

    /**
     * hasPreviousPage is true only when currentPage > 1.
     */
    #[Test]
    #[DataProvider('navigationScenarios')]
    public function hasPreviousPageIsConsistent(int $total, int $perPage, int $currentPage): void
    {
        $paginator = new Paginator([], $total, $currentPage, $perPage);

        if ($currentPage > 1) {
            self::assertTrue($paginator->hasPreviousPage(), "Page {$currentPage} should have a previous page");
        } else {
            self::assertFalse($paginator->hasPreviousPage(), 'Page 1 should not have a previous page');
        }
    }

    /**
     * nextPage returns currentPage + 1 when there are more pages, null otherwise.
     */
    #[Test]
    #[DataProvider('navigationScenarios')]
    public function nextPageIsCorrect(int $total, int $perPage, int $currentPage): void
    {
        $paginator = new Paginator([], $total, $currentPage, $perPage);

        if ($paginator->hasMorePages()) {
            self::assertSame($currentPage + 1, $paginator->nextPage());
        } else {
            self::assertNull($paginator->nextPage());
        }
    }

    /**
     * previousPage returns currentPage - 1 when not on page 1, null otherwise.
     */
    #[Test]
    #[DataProvider('navigationScenarios')]
    public function previousPageIsCorrect(int $total, int $perPage, int $currentPage): void
    {
        $paginator = new Paginator([], $total, $currentPage, $perPage);

        if ($currentPage > 1) {
            self::assertSame($currentPage - 1, $paginator->previousPage());
        } else {
            self::assertNull($paginator->previousPage());
        }
    }

    /**
     * The links() array always contains the current page as active.
     */
    #[Test]
    #[DataProvider('navigationScenarios')]
    public function linksAlwaysContainCurrentPageAsActive(int $total, int $perPage, int $currentPage): void
    {
        $paginator = new Paginator([], $total, $currentPage, $perPage);
        $links = $paginator->links();

        $activeLinks = array_filter($links, static fn(PageLink $link): bool => $link->isActive);

        self::assertCount(1, $activeLinks, "Exactly one link should be active (current page {$currentPage})");

        $activeLink = reset($activeLinks);
        self::assertNotFalse($activeLink);
        self::assertSame($currentPage, $activeLink->page);
    }

    /**
     * Zero total always yields lastPage = 1.
     */
    #[Test]
    public function zeroTotalAlwaysYieldsLastPageOne(): void
    {
        $perPageValues = [1, 5, 10, 15, 25, 50, 100];

        foreach ($perPageValues as $perPage) {
            $paginator = new Paginator([], 0, 1, $perPage);
            self::assertSame(1, $paginator->lastPage, "Zero total with perPage={$perPage} should have lastPage=1");
            self::assertFalse($paginator->hasMorePages());
        }
    }

    /**
     * Single-item pages: when perPage = 1, lastPage = total.
     */
    #[Test]
    public function singleItemPerPageLastPageEqualsTotal(): void
    {
        foreach ([1, 2, 3, 5, 10, 50, 100] as $total) {
            $paginator = new Paginator([], $total, 1, 1);
            self::assertSame($total, $paginator->lastPage, "With perPage=1 and total={$total}, lastPage should be {$total}");
        }
    }

    /**
     * toResult preserves all paginator properties.
     */
    #[Test]
    public function toResultPreservesProperties(): void
    {
        $items = ['a', 'b', 'c'];
        $paginator = new Paginator($items, 100, 3, 10);
        $result = $paginator->toResult();

        self::assertSame($items, $result->items);
        self::assertSame(100, $result->total);
        self::assertSame(10, $result->perPage);
        self::assertSame(3, $result->currentPage);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function perPageAndTotalCombinations(): iterable
    {
        // Exact divisions
        yield 'total=10, perPage=5' => [10, 5];
        yield 'total=100, perPage=10' => [100, 10];
        yield 'total=1, perPage=1' => [1, 1];
        yield 'total=50, perPage=25' => [50, 25];

        // Remainder cases
        yield 'total=11, perPage=5' => [11, 5];
        yield 'total=1, perPage=10' => [1, 10];
        yield 'total=99, perPage=10' => [99, 10];
        yield 'total=7, perPage=3' => [7, 3];

        // Edge cases
        yield 'total=0, perPage=10' => [0, 10];
        yield 'total=0, perPage=1' => [0, 1];
        yield 'total=1000, perPage=1' => [1000, 1];
        yield 'total=1, perPage=1000' => [1, 1000];

        // Large values
        yield 'total=999999, perPage=100' => [999999, 100];
        yield 'total=10000, perPage=3' => [10000, 3];
        yield 'total=1, perPage=999999' => [1, 999999];
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function collectionSlicingScenarios(): iterable
    {
        // Normal pagination
        yield 'page 1 of 3' => [30, 10, 1];
        yield 'page 2 of 3' => [30, 10, 2];
        yield 'page 3 of 3' => [30, 10, 3];

        // Last page partial
        yield 'partial last page' => [25, 10, 3];

        // Single page
        yield 'single page' => [5, 10, 1];

        // Beyond last page
        yield 'beyond last page' => [10, 10, 5];

        // Zero or negative page (clamped to 1)
        yield 'page 0 clamped' => [20, 10, 0];
        yield 'negative page clamped' => [20, 10, -1];

        // Empty collection
        yield 'empty collection' => [0, 10, 1];

        // Single item
        yield 'single item page 1' => [1, 10, 1];
        yield 'single item perPage=1' => [1, 1, 1];

        // Large collection
        yield 'large collection first page' => [1000, 25, 1];
        yield 'large collection middle page' => [1000, 25, 20];
        yield 'large collection last page' => [1000, 25, 40];
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function navigationScenarios(): iterable
    {
        // First page
        yield 'first of many' => [100, 10, 1];
        yield 'first of one' => [5, 10, 1];

        // Middle pages
        yield 'middle page' => [100, 10, 5];
        yield 'middle of 3' => [30, 10, 2];

        // Last page
        yield 'last page exact' => [100, 10, 10];
        yield 'last page partial' => [95, 10, 10];

        // Single page
        yield 'single page only' => [3, 10, 1];

        // Various sizes
        yield 'page 1 of 2' => [20, 10, 1];
        yield 'page 2 of 2' => [20, 10, 2];
        yield 'large total page 1' => [10000, 25, 1];
        yield 'large total last page' => [10000, 25, 400];
    }
}
