<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_slice;
use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Cursor-based pagination.
 *
 * Uses opaque base64-encoded cursors to traverse datasets. Cursors encode
 * the position reference (e.g., last seen ID) and are safe from enumeration
 * attacks since they don't expose sequential offsets.
 *
 * Best for real-time feeds, infinite scroll, and large datasets where
 * total count is expensive.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CursorPaginator implements PaginatorInterface
{
    /**
     * @param list<mixed> $items Items after cursor application (perPage + 1 for has_more detection)
     * @param int $totalOrEstimate Total count (-1 if unknown)
     * @param PaginationRequest $request Pagination parameters with cursor
     * @param string $baseUrl Base URL for link generation
     *
     * @return PaginationResult<mixed>
     */
    #[Override]
    #[NoDiscard]
    public function paginate(
        array $items,
        int $totalOrEstimate,
        PaginationRequest $request,
        string $baseUrl = '',
    ): PaginationResult {
        $perPage = $request->perPage;
        $total = $totalOrEstimate >= 0 ? $totalOrEstimate : null;

        // If we got perPage+1 items, there are more pages
        $hasMore = count($items) > $perPage;
        $pageItems = $hasMore ? array_slice($items, 0, $perPage) : $items;

        // Encode cursors
        $nextCursor = $hasMore && $pageItems !== [] ? self::encode($perPage, $pageItems) : null;
        $currentCursor = $request->cursor ?? $request->after;

        // Generate links
        $links = $this->buildLinks($baseUrl, $perPage, $nextCursor);

        return new PaginationResult(
            items: $pageItems,
            total: $total,
            hasMore: $hasMore,
            perPage: $perPage,
            cursor: $currentCursor,
            nextCursor: $nextCursor,
            links: $links,
        );
    }

    /**
     * Decode an opaque cursor into its position data.
     *
     * @return array<string, mixed>|null Decoded cursor data, or null if invalid
     */
    #[NoDiscard]
    public static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);

        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Encode position data into an opaque cursor.
     *
     * @param list<mixed> $pageItems Items in the current page
     */
    private static function encode(int $perPage, array $pageItems): string
    {
        /** @var int<0, max> $lastIndex */
        $lastIndex = count($pageItems) - 1;
        /** @var mixed $lastItem */
        $lastItem = $pageItems[$lastIndex];
        /** @var mixed $position */
        $position = is_array($lastItem) ? ($lastItem['id'] ?? count($pageItems)) : count($pageItems);

        return base64_encode(json_encode([
            'p' => $position,
            'n' => $perPage,
        ], JSON_THROW_ON_ERROR));
    }

    private function buildLinks(string $baseUrl, int $perPage, ?string $nextCursor): PaginationLinks
    {
        if ($baseUrl === '') {
            return new PaginationLinks();
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        $first = sprintf('%s%sper_page=%d', $baseUrl, $separator, $perPage);
        $next = $nextCursor !== null ? sprintf('%s%safter=%s&per_page=%d', $baseUrl, $separator, $nextCursor, $perPage) : null;

        return new PaginationLinks(
            first: $first,
            next: $next,
        );
    }
}
