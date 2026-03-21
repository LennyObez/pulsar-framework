<?php

declare(strict_types=1);

namespace Pulsar\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

use function array_slice;
use function ceil;
use function count;
use function max;
use function min;
use function range;

/**
 * Page-based paginator: knows the total count and produces numbered page links.
 *
 * @template T
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Paginator
{
    public int $lastPage;

    /**
     * @param list<T> $items Items for the current page
     * @param int $total Total number of items across all pages
     * @param int $currentPage Current page number (1-based)
     * @param int $perPage Items per page
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $perPage = 15,
    ) {
        $this->lastPage = ($perPage > 0 && $total > 0) ? (int) ceil($total / $perPage) : 1;
    }

    /**
     * Create a paginator from a full collection by slicing.
     *
     * Useful for in-memory pagination of small datasets.
     *
     * @template U
     * @param list<U> $allItems
     * @return self<U>
     */
    #[NoDiscard]
    public static function fromCollection(array $allItems, int $currentPage, int $perPage = 15): self
    {
        $page = max(1, $currentPage);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($allItems, $offset, $perPage);

        return new self($items, count($allItems), $page, $perPage);
    }

    /**
     * Whether there are more pages after the current one.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Whether there are previous pages.
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Get the previous page number, or null if on the first page.
     */
    public function previousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    /**
     * Get the next page number, or null if on the last page.
     */
    public function nextPage(): ?int
    {
        return $this->currentPage < $this->lastPage ? $this->currentPage + 1 : null;
    }

    /**
     * Generate an array of page link descriptors for rendering.
     *
     * Produces a sliding window of page numbers around the current page,
     * with ellipsis markers where pages are skipped.
     *
     * @param int $onEachSide Number of page links on each side of the current page
     * @return list<PageLink>
     */
    #[NoDiscard]
    public function links(int $onEachSide = 3): array
    {
        if ($this->lastPage <= 1) {
            return [new PageLink(1, '1', true, false)];
        }

        $links = [];

        // Previous arrow
        $links[] = new PageLink(
            page: $this->previousPage() ?? 1,
            label: '&laquo;',
            isActive: false,
            isDisabled: !$this->hasPreviousPage(),
        );

        $windowStart = max(1, $this->currentPage - $onEachSide);
        $windowEnd = min($this->lastPage, $this->currentPage + $onEachSide);

        // First page + ellipsis if needed
        if ($windowStart > 1) {
            $links[] = new PageLink(1, '1', false, false);

            if ($windowStart > 2) {
                $links[] = PageLink::ellipsis();
            }
        }

        // Window pages
        foreach (range($windowStart, $windowEnd) as $page) {
            $links[] = new PageLink(
                page: $page,
                label: (string) $page,
                isActive: $page === $this->currentPage,
                isDisabled: false,
            );
        }

        // Last page + ellipsis if needed
        if ($windowEnd < $this->lastPage) {
            if ($windowEnd < $this->lastPage - 1) {
                $links[] = PageLink::ellipsis();
            }

            $links[] = new PageLink($this->lastPage, (string) $this->lastPage, false, false);
        }

        // Next arrow
        $links[] = new PageLink(
            page: $this->nextPage() ?? $this->lastPage,
            label: '&raquo;',
            isActive: false,
            isDisabled: !$this->hasMorePages(),
        );

        return $links;
    }

    /**
     * Convert to a PaginationResult DTO.
     *
     * @return PaginationResult<T>
     */
    #[NoDiscard]
    public function toResult(): PaginationResult
    {
        return new PaginationResult($this->items, $this->total, $this->perPage, $this->currentPage);
    }
}
