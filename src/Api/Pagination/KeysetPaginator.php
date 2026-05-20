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
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function urlencode;

use const JSON_THROW_ON_ERROR;

/**
 * Keyset pagination for efficient large dataset traversal.
 *
 * Uses the last seen values of the sort key(s) to construct a WHERE clause
 * for the next page. This avoids the O(offset) performance problem of
 * traditional offset pagination and provides stable results under concurrent writes.
 *
 * Best for ordered datasets with a unique sort key (e.g., created_at + id).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class KeysetPaginator implements PaginatorInterface
{
    /**
     * @param string $sortKey The primary sort key field name (e.g., 'id', 'created_at')
     */
    public function __construct(
        private string $sortKey = 'id',
    ) {}

    /**
     * @param list<mixed> $items Items after keyset filter (perPage + 1 for has_more detection)
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

        // Extract the keyset values from the last item for the next cursor
        $nextCursor = null;

        if ($hasMore && $pageItems !== []) {
            /** @var mixed $lastItem */
            $lastItem = $pageItems[count($pageItems) - 1];
            /** @var mixed $keysetValue */
            $keysetValue = is_array($lastItem) ? ($lastItem[$this->sortKey] ?? null) : null;

            if ($keysetValue !== null) {
                $nextCursor = self::encodeCursor($this->sortKey, $keysetValue);
            }
        }

        // Decode current cursor for prev link
        $currentCursor = $request->cursor ?? $request->after;

        // First item for reverse cursor
        $prevCursor = null;

        if ($currentCursor !== null && $pageItems !== []) {
            /** @var mixed $firstItem */
            $firstItem = $pageItems[0];
            /** @var mixed $keysetValue */
            $keysetValue = is_array($firstItem) ? ($firstItem[$this->sortKey] ?? null) : null;

            if ($keysetValue !== null) {
                $prevCursor = self::encodeCursor($this->sortKey, $keysetValue);
            }
        }

        $links = $this->buildLinks($baseUrl, $perPage, $nextCursor, $currentCursor);

        return new PaginationResult(
            items: $pageItems,
            total: $total,
            hasMore: $hasMore,
            perPage: $perPage,
            cursor: $currentCursor,
            nextCursor: $nextCursor,
            prevCursor: $prevCursor,
            links: $links,
        );
    }

    /**
     * Decode a keyset cursor into field/value pairs.
     *
     * @return array{key: string, value: mixed}|null
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

        if (!is_array($data) || !isset($data['k'], $data['v'])) {
            return null;
        }

        $key = $data['k'];

        if (!is_string($key)) {
            return null;
        }

        /** @var array{key: string, value: mixed} */
        return ['key' => $key, 'value' => $data['v']];
    }

    private static function encodeCursor(string $key, mixed $value): string
    {
        return base64_encode(json_encode([
            'k' => $key,
            'v' => $value,
        ], JSON_THROW_ON_ERROR));
    }

    private function buildLinks(string $baseUrl, int $perPage, ?string $nextCursor, ?string $currentCursor): PaginationLinks
    {
        if ($baseUrl === '') {
            return new PaginationLinks();
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        $first = sprintf('%s%sper_page=%d', $baseUrl, $separator, $perPage);
        $next = $nextCursor !== null ? sprintf('%s%safter=%s&per_page=%d', $baseUrl, $separator, urlencode($nextCursor), $perPage) : null;

        return new PaginationLinks(
            first: $first,
            next: $next,
        );
    }
}
