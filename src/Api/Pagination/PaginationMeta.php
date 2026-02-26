<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Pagination metadata for response rendering.
 *
 * Provides a serializable summary of pagination state that renderers
 * can include in response envelopes.
 */
#[Api(since: '1.0.0')]
final readonly class PaginationMeta
{
    /**
     * @param int $perPage Items per page
     * @param bool $hasMore Whether there are more items beyond this page
     * @param int|null $total Total count of all items
     * @param int|null $currentPage Current page number (offset pagination)
     * @param int|null $lastPage Last page number (offset pagination)
     * @param string|null $cursor Current cursor position
     * @param string|null $nextCursor Next page cursor
     * @param string|null $prevCursor Previous page cursor
     */
    public function __construct(
        public int $perPage,
        public bool $hasMore,
        public ?int $total = null,
        public ?int $currentPage = null,
        public ?int $lastPage = null,
        public ?string $cursor = null,
        public ?string $nextCursor = null,
        public ?string $prevCursor = null,
    ) {}

    /**
     * Build from a PaginationResult.
     *
     * @param PaginationResult<mixed> $result
     */
    #[NoDiscard]
    public static function fromResult(PaginationResult $result): self
    {
        return new self(
            perPage: $result->perPage,
            hasMore: $result->hasMore,
            total: $result->total,
            currentPage: $result->currentPage,
            lastPage: $result->lastPage,
            cursor: $result->cursor,
            nextCursor: $result->nextCursor,
            prevCursor: $result->prevCursor,
        );
    }

    /**
     * Serialize to array, omitting null values.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
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

        return $meta;
    }
}
