<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Encapsulates pagination parameters from the client request.
 *
 * Validates and normalizes page size, page number, and cursor values
 * against configured limits.
 */
#[Api(since: '1.0.0')]
final readonly class PaginationRequest
{
    /**
     * @param int $perPage Number of items per page (validated against limits)
     * @param int $page Page number for offset pagination (1-based)
     * @param string|null $cursor Opaque cursor for cursor/keyset pagination
     * @param string|null $after Cursor to fetch items after (alias for cursor)
     * @param string|null $before Cursor to fetch items before (reverse pagination)
     */
    public function __construct(
        public int $perPage,
        public int $page = 1,
        public ?string $cursor = null,
        public ?string $after = null,
        public ?string $before = null,
    ) {}

    /**
     * Build from raw query parameters with validation.
     *
     * @param array<string, mixed> $query Raw query parameters from the HTTP request
     * @param int $defaultSize Default page size from config
     * @param int $maxSize Maximum page size from config
     */
    #[NoDiscard]
    public static function fromQuery(array $query, int $defaultSize = 25, int $maxSize = 100): self
    {
        $rawPerPage = $query['per_page'] ?? $query['limit'] ?? $defaultSize;
        $perPage = is_int($rawPerPage) ? $rawPerPage : (is_numeric($rawPerPage) ? (int) $rawPerPage : $defaultSize);
        $perPage = max(1, min($perPage, $maxSize));

        $rawPage = $query['page'] ?? 1;
        $page = is_int($rawPage) ? $rawPage : (is_numeric($rawPage) ? (int) $rawPage : 1);
        $page = max(1, $page);

        $rawCursor = $query['cursor'] ?? null;
        $cursor = is_string($rawCursor) && $rawCursor !== '' ? $rawCursor : null;

        $rawAfter = $query['after'] ?? null;
        $after = is_string($rawAfter) && $rawAfter !== '' ? $rawAfter : null;

        $rawBefore = $query['before'] ?? null;
        $before = is_string($rawBefore) && $rawBefore !== '' ? $rawBefore : null;

        return new self(
            perPage: $perPage,
            page: $page,
            cursor: $cursor,
            after: $after,
            before: $before,
        );
    }

    /**
     * Compute the offset for offset-based pagination.
     */
    #[NoDiscard]
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
