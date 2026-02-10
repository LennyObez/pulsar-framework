<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * Value object containing paginated items, metadata, and links.
 *
 * Provides a consistent format across all pagination strategies.
 *
 * @template T
 */
#[Api(since: '1.0.0')]
final readonly class PaginationResult
{
    /**
     * @param list<T> $items The items for the current page
     * @param int|null $total Total count of all items (null if unknown/expensive)
     * @param bool $hasMore Whether there are more items beyond this page
     * @param int $perPage Number of items per page
     * @param string|null $cursor Current cursor position (cursor/keyset pagination)
     * @param string|null $nextCursor Cursor for the next page (cursor/keyset pagination)
     * @param string|null $prevCursor Cursor for the previous page (cursor/keyset pagination)
     * @param int|null $currentPage Current page number (offset pagination)
     * @param int|null $lastPage Last page number (offset pagination)
     * @param PaginationLinks $links HATEOAS pagination links
     */
    public function __construct(
        public array $items,
        public ?int $total,
        public bool $hasMore,
        public int $perPage,
        public ?string $cursor = null,
        public ?string $nextCursor = null,
        public ?string $prevCursor = null,
        public ?int $currentPage = null,
        public ?int $lastPage = null,
        public PaginationLinks $links = new PaginationLinks(),
    ) {}

    /**
     * Serialize the pagination metadata to array.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function metaToArray(): array
    {
        $meta = [
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
        ];

        if ($this->total !== null) {
            $meta['total'] = $this->total;
        }

        if ($this->currentPage !== null) {
            $meta['current_page'] = $this->currentPage;
        }

        if ($this->lastPage !== null) {
            $meta['last_page'] = $this->lastPage;
        }

        if ($this->cursor !== null) {
            $meta['cursor'] = $this->cursor;
        }

        if ($this->nextCursor !== null) {
            $meta['next_cursor'] = $this->nextCursor;
        }

        if ($this->prevCursor !== null) {
            $meta['prev_cursor'] = $this->prevCursor;
        }

        $links = $this->links->toArray();

        if ($links !== []) {
            $meta['links'] = $links;
        }

        return $meta;
    }

    /**
     * Get the number of items in this page.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if this page is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
