<?php

declare(strict_types=1);

namespace Pulsar\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * Immutable DTO encapsulating paginated items and their metadata.
 *
 * @template T
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PaginationResult
{
    /** Total number of pages. */
    public int $lastPage;

    /**
     * @param list<T> $items The items for the current page
     * @param int $total Total number of items across all pages
     * @param int $perPage Number of items per page
     * @param int $currentPage Current page number (1-based)
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {
        $this->lastPage = ($perPage > 0 && $total > 0) ? (int) ceil($total / $perPage) : 1;
    }

    /**
     * Whether there are more pages after the current one.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Whether this is the first page.
     */
    public function isFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    /**
     * Whether this is the last page.
     */
    public function isLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage;
    }

    /**
     * Get the number of items on the current page.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Whether the result set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Calculate the offset for the current page.
     */
    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    /**
     * Serialize to an API-friendly array (items + meta block).
     *
     * @return array{items: list<T>, meta: array{current_page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'meta' => [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
                'has_more' => $this->hasMorePages(),
            ],
        ];
    }
}
