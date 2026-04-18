<?php

declare(strict_types=1);

namespace Pulsar\Support;

use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use NoDiscard;
use Pulsar\Api\Api;
use Traversable;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function usort;

/**
 * Typed, fluent collection with functional combinators.
 *
 * Wraps a list of items and provides filter, map, pluck, unique, first, last,
 * count, toArray, reduce, flatMap, groupBy, sortBy, contains, and isEmpty.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 * @api
 */
#[Api(since: '1.0.0')]
final class Collection implements Countable, IteratorAggregate
{
    /**
     * @param list<T> $items
     */
    private function __construct(
        private readonly array $items,
    ) {}

    /**
     * Create a new collection from an array.
     *
     * @template U
     *
     * @param list<U> $items
     *
     * @return self<U>
     */
    public static function of(array $items): self
    {
        return new self(array_values($items));
    }

    /**
     * Create an empty collection.
     *
     * @return self<mixed>
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Filter items using a callback.
     *
     * @param Closure(T, int): bool $callback
     *
     * @return self<T>
     */
    #[NoDiscard]
    public function filter(Closure $callback): self
    {
        $result = [];

        foreach ($this->items as $index => $item) {
            if ($callback($item, $index)) {
                $result[] = $item;
            }
        }

        return new self($result);
    }

    /**
     * Transform each item using a callback.
     *
     * @template U
     *
     * @param Closure(T, int): U $callback
     *
     * @return self<U>
     */
    #[NoDiscard]
    public function map(Closure $callback): self
    {
        return new self(array_values(array_map($callback, $this->items, array_keys($this->items))));
    }

    /**
     * Pluck a single key from each item.
     *
     * Works with arrays and objects (public properties or methods).
     *
     * @return self<mixed>
     */
    #[NoDiscard]
    public function pluck(string $key): self
    {
        $result = [];

        foreach ($this->items as $item) {
            if (is_array($item) && array_key_exists($key, $item)) {
                $result[] = $item[$key];
            } elseif (is_object($item) && property_exists($item, $key)) {
                $result[] = $item->{$key};
            } elseif (is_object($item) && method_exists($item, $key)) {
                $result[] = $item->{$key}();
            }
        }

        return new self($result);
    }

    /**
     * Remove duplicate values (strict comparison).
     *
     * @return self<T>
     */
    #[NoDiscard]
    public function unique(): self
    {
        return new self(array_values(array_unique($this->items, SORT_REGULAR)));
    }

    /**
     * Get the first item, optionally matching a callback.
     *
     * @param (Closure(T): bool)|null $callback
     *
     * @return T|null
     */
    public function first(?Closure $callback = null): mixed
    {
        if ($callback === null) {
            return $this->items[0] ?? null;
        }

        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Get the last item, optionally matching a callback.
     *
     * @param (Closure(T): bool)|null $callback
     *
     * @return T|null
     */
    public function last(?Closure $callback = null): mixed
    {
        if ($callback === null) {
            $copy = $this->items;

            return array_pop($copy);
        }

        $reversed = array_reverse($this->items);

        foreach ($reversed as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Count the items in the collection.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Convert to a plain array.
     *
     * @return list<T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Reduce the collection to a single value.
     *
     * @template U
     *
     * @param Closure(U, T, int): U $callback
     * @param U $initial
     *
     * @return U
     */
    public function reduce(Closure $callback, mixed $initial): mixed
    {
        $carry = $initial;

        foreach ($this->items as $index => $item) {
            $carry = $callback($carry, $item, $index);
        }

        return $carry;
    }

    /**
     * Map each item to a list and flatten the result one level.
     *
     * @template U
     *
     * @param Closure(T, int): list<U> $callback
     *
     * @return self<U>
     */
    #[NoDiscard]
    public function flatMap(Closure $callback): self
    {
        $result = [];

        foreach ($this->items as $index => $item) {
            $mapped = $callback($item, $index);
            $result = array_merge($result, $mapped);
        }

        return new self(array_values($result));
    }

    /**
     * Group items by a key or callback.
     *
     * @param string|Closure(T): string $keyOrCallback
     *
     * @return array<string, list<T>>
     */
    #[NoDiscard]
    public function groupBy(string|Closure $keyOrCallback): array
    {
        $groups = [];

        foreach ($this->items as $item) {
            if (is_string($keyOrCallback)) {
                if (is_array($item)) {
                    $raw = $item[$keyOrCallback] ?? '';
                } elseif (is_object($item) && property_exists($item, $keyOrCallback)) {
                    $raw = $item->{$keyOrCallback};
                } else {
                    $raw = '';
                }

                $group = is_string($raw) || is_int($raw) ? (string) $raw : '';
            } else {
                $group = $keyOrCallback($item);
            }

            $groups[$group][] = $item;
        }

        return $groups;
    }

    /**
     * Sort items by a key or callback.
     *
     * @param string|Closure(T): mixed $keyOrCallback
     *
     * @return self<T>
     */
    #[NoDiscard]
    public function sortBy(string|Closure $keyOrCallback): self
    {
        $items = $this->items;

        usort($items, static function (mixed $a, mixed $b) use ($keyOrCallback): int {
            if (is_string($keyOrCallback)) {
                /** @var mixed $va */
                $va = is_array($a) ? ($a[$keyOrCallback] ?? null) : (is_object($a) && property_exists($a, $keyOrCallback) ? $a->{$keyOrCallback} : null);
                /** @var mixed $vb */
                $vb = is_array($b) ? ($b[$keyOrCallback] ?? null) : (is_object($b) && property_exists($b, $keyOrCallback) ? $b->{$keyOrCallback} : null);
            } else {
                /** @var mixed $va */
                $va = $keyOrCallback($a);
                /** @var mixed $vb */
                $vb = $keyOrCallback($b);
            }

            return $va <=> $vb;
        });

        return new self($items);
    }

    /**
     * Check if the collection contains a value or matches a callback.
     *
     * @param T|Closure(T): bool $valueOrCallback
     */
    public function contains(mixed $valueOrCallback): bool
    {
        if ($valueOrCallback instanceof Closure) {
            foreach ($this->items as $item) {
                if ($valueOrCallback($item)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($valueOrCallback, $this->items, true);
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Check if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Take the first N items.
     *
     * @return self<T>
     */
    #[NoDiscard]
    public function take(int $limit): self
    {
        return new self(array_slice($this->items, 0, $limit));
    }

    /**
     * Skip the first N items.
     *
     * @return self<T>
     */
    #[NoDiscard]
    public function skip(int $count): self
    {
        return new self(array_values(array_slice($this->items, $count)));
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
