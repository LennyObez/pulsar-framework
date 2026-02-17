<?php

declare(strict_types=1);

namespace Pulsar\Pagination;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;

use function array_slice;
use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Cursor-based paginator for efficient large-dataset traversal.
 *
 * Avoids COUNT queries and OFFSET scanning by using an opaque cursor
 * that encodes the position of the last seen item.
 *
 * @template T
 */
#[Api(since: '1.0.0')]
final readonly class CursorPaginator
{
    /** Whether there are more items after this page. */
    public bool $hasMore;

    /** @var list<T> Items for this page (at most $perPage items). */
    public array $items;

    /**
     * @param list<T> $fetchedItems Items fetched (perPage + 1 to detect next page)
     * @param int $perPage Number of items per page
     * @param string|null $cursor Current cursor (null for first page)
     * @param string $cursorColumn Column used for cursor positioning
     */
    public function __construct(
        array $fetchedItems,
        public int $perPage,
        public ?string $cursor,
        public string $cursorColumn = 'id',
    ) {
        $this->hasMore = count($fetchedItems) > $perPage;
        $this->items = $this->hasMore ? array_slice($fetchedItems, 0, $perPage) : $fetchedItems;
    }

    /**
     * Get the cursor for the next page.
     *
     * Returns an opaque base64-encoded cursor string encoding the last
     * item's position, or null if there are no more pages.
     *
     * @param callable(T): string $valueExtractor Extracts the cursor column value from an item
     */
    #[NoDiscard]
    public function nextCursor(callable $valueExtractor): ?string
    {
        if (!$this->hasMore || $this->items === []) {
            return null;
        }

        $lastItem = $this->items[count($this->items) - 1];
        $value = $valueExtractor($lastItem);

        return self::encodeCursor($this->cursorColumn, $value);
    }

    /**
     * Decode a cursor string into its column and value components.
     *
     * @return array{column: string, value: string}|null Null if the cursor is invalid
     */
    #[NoDiscard]
    public static function decodeCursor(string $cursor): ?array
    {
        $json = base64_decode($cursor, true);

        if (!is_string($json) || $json === '') {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['c'], $data['v']) || !is_string($data['c']) || !is_string($data['v'])) {
            return null;
        }

        return ['column' => $data['c'], 'value' => $data['v']];
    }

    /**
     * Encode a cursor from column and value.
     */
    #[NoDiscard]
    public static function encodeCursor(string $column, string $value): string
    {
        return base64_encode(json_encode(['c' => $column, 'v' => $value], JSON_THROW_ON_ERROR));
    }

    /**
     * Serialize to an API-friendly array.
     *
     * @param callable(T): string $valueExtractor Extracts the cursor column value from an item
     * @return array{items: list<T>, meta: array{per_page: int, has_more: bool, next_cursor: string|null}}
     */
    #[NoDiscard]
    public function toArray(callable $valueExtractor): array
    {
        return [
            'items' => $this->items,
            'meta' => [
                'per_page' => $this->perPage,
                'has_more' => $this->hasMore,
                'next_cursor' => $this->nextCursor($valueExtractor),
            ],
        ];
    }
}
