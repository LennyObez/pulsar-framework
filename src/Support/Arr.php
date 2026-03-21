<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function property_exists;
use function usort;

/**
 * Fluent array manipulation utilities.
 *
 * All methods are pure and static: no mutable state.
 * Supports dot-notation access for nested structures.
 * @api
 */
#[Api(since: '1.0.0')]
final class Arr
{
    private function __construct() {}

    /**
     * Get a value from a nested array using dot notation.
     *
     * @param array<string, mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $segments = explode('.', $key);
        $current = $array;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a value in a nested array using dot notation.
     *
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public static function set(array $array, string $key, mixed $value): array
    {
        $segments = explode('.', $key);
        $current = &$array;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }

                $current = &$current[$segment];
            }
        }

        return $array;
    }

    /**
     * Check if a key exists in a nested array using dot notation.
     *
     * @param array<string, mixed> $array
     */
    public static function has(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        $segments = explode('.', $key);
        $current = $array;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Pluck a single key from each sub-array or object.
     *
     * @param list<array<string, mixed>|object> $array
     *
     * @return list<mixed>
     */
    #[NoDiscard]
    public static function pluck(array $array, string $key): array
    {
        $result = [];

        foreach ($array as $item) {
            if (is_array($item) && array_key_exists($key, $item)) {
                $result[] = $item[$key];
            } elseif (is_object($item) && property_exists($item, $key)) {
                $result[] = $item->{$key};
            }
        }

        return $result;
    }

    /**
     * Remove duplicate values from an array.
     *
     * @param list<mixed> $array
     *
     * @return list<mixed>
     */
    #[NoDiscard]
    public static function unique(array $array): array
    {
        return array_values(array_unique($array, SORT_REGULAR));
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param array<mixed> $array
     *
     * @return list<mixed>
     */
    #[NoDiscard]
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];

        foreach ($array as $item) {
            if (is_array($item) && $depth > 0) {
                $result = array_merge($result, self::flatten($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Group items by a key.
     *
     * @param list<array<string, mixed>> $array
     *
     * @return array<string, list<array<string, mixed>>>
     */
    #[NoDiscard]
    public static function groupBy(array $array, string $key): array
    {
        $groups = [];

        foreach ($array as $item) {
            $raw = $item[$key] ?? '';
            $group = is_string($raw) || is_int($raw) ? (string) $raw : '';
            $groups[$group][] = $item;
        }

        return $groups;
    }

    /**
     * Sort items by a key.
     *
     * @param list<array<string, mixed>> $array
     *
     * @return list<array<string, mixed>>
     */
    #[NoDiscard]
    public static function sortBy(array $array, string $key): array
    {
        usort($array, static fn(array $a, array $b): int => ($a[$key] ?? null) <=> ($b[$key] ?? null));

        return $array;
    }

    /**
     * Get the first element, optionally matching a callback.
     *
     * @param list<mixed> $array
     * @param (Closure(mixed): bool)|null $callback
     */
    public static function first(array $array, ?Closure $callback = null): mixed
    {
        if ($callback === null) {
            return $array[0] ?? null;
        }

        foreach ($array as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Get the last element, optionally matching a callback.
     *
     * @param list<mixed> $array
     * @param (Closure(mixed): bool)|null $callback
     */
    public static function last(array $array, ?Closure $callback = null): mixed
    {
        if ($callback === null) {
            $copy = $array;

            return array_pop($copy);
        }

        $reversed = array_reverse($array);

        foreach ($reversed as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Remove one or more keys from an array.
     *
     * @param array<string, mixed> $array
     * @param string|list<string> $keys
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public static function except(array $array, string|array $keys): array
    {
        $keysToRemove = is_array($keys) ? $keys : [$keys];
        $result = $array;

        foreach ($keysToRemove as $key) {
            unset($result[$key]);
        }

        return $result;
    }

    /**
     * Keep only the specified keys.
     *
     * @param array<string, mixed> $array
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public static function only(array $array, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $result[$key] = $array[$key];
            }
        }

        return $result;
    }

    /**
     * Return items from the array where the callback returns true.
     *
     * @param list<mixed> $array
     * @param Closure(mixed, int): bool $callback
     *
     * @return list<mixed>
     */
    #[NoDiscard]
    public static function where(array $array, Closure $callback): array
    {
        $result = [];

        foreach ($array as $index => $item) {
            if ($callback($item, $index)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Wrap a value in an array if it isn't one already.
     *
     * @return list<mixed>
     */
    #[NoDiscard]
    public static function wrap(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return $value === null ? [] : [$value];
    }
}
