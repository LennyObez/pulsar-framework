<?php

declare(strict_types=1);

namespace Pulsar\Support\Json;

use Closure;
use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * JSON Path query engine per RFC 9535.
 *
 * Compiles path expressions into optimized PHP closures on first use,
 * then caches them for repeated execution against different documents.
 *
 * Supported syntax:
 * - $.store.book[0]          : indexed access
 * - $.store.book[*]          : wildcard array access
 * - $..author                : recursive descent
 * - $.store.book[?(@.price < 10)]: filter expressions
 * - $.store.book[-1]         : negative indexing
 * - $.store.book[0:3]        : array slicing
 * @api
 */
#[Api(since: '1.0.0')]
final class JsonPath
{
    /** @var array<string, Closure(mixed): list<mixed>> */
    private static array $cache = [];

    /**
     * Query a data structure using a JSON Path expression.
     *
     * @param mixed $data The data to query (typically decoded JSON)
     * @param string $path The JSON Path expression
     * @return list<mixed> Matched values
     *
     * @throws InvalidArgumentException If path syntax is invalid
     */
    #[NoDiscard]
    public static function query(mixed $data, string $path): array
    {
        $fn = self::$cache[$path] ?? null;

        if ($fn === null) {
            $fn = self::compile($path);
            self::$cache[$path] = $fn;
        }

        return $fn($data);
    }

    /**
     * Query and return the first matching value, or null if none.
     *
     * @throws InvalidArgumentException If path syntax is invalid
     */
    #[NoDiscard]
    public static function first(mixed $data, string $path): mixed
    {
        $results = self::query($data, $path);

        return $results[0] ?? null;
    }

    /**
     * Check whether a path matches any values in the data.
     *
     * @throws InvalidArgumentException If path syntax is invalid
     */
    #[NoDiscard]
    public static function exists(mixed $data, string $path): bool
    {
        return self::query($data, $path) !== [];
    }

    /**
     * Clear the compiled path cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Compile a JSON Path expression into a closure.
     *
     * @return Closure(mixed): list<mixed>
     */
    private static function compile(string $path): Closure
    {
        if (!str_starts_with($path, '$')) {
            throw new InvalidArgumentException("JSON Path must start with '$': $path");
        }

        $segments = self::parse(substr($path, 1));

        return static function (mixed $data) use ($segments): array {
            $current = [$data];

            foreach ($segments as $segment) {
                $next = [];

                foreach ($current as $node) {
                    $next = [...$next, ...$segment($node)];
                }

                $current = $next;
            }

            return $current;
        };
    }

    /**
     * Parse a path string into segment closures.
     *
     * @return list<Closure(mixed): list<mixed>>
     */
    private static function parse(string $path): array
    {
        $segments = [];
        $pos = 0;
        $len = strlen($path);

        while ($pos < $len) {
            // Recursive descent (..)
            if ($pos + 1 < $len && $path[$pos] === '.' && $path[$pos + 1] === '.') {
                $pos += 2;
                $key = self::readKey($path, $pos);

                $segments[] = static function (mixed $node) use ($key): array {
                    return self::recursiveDescend($node, $key);
                };

                continue;
            }

            // Dot accessor
            if ($path[$pos] === '.') {
                $pos++;
                $key = self::readKey($path, $pos);

                if ($key === '*') {
                    $segments[] = static function (mixed $node): array {
                        if (!is_array($node)) {
                            return [];
                        }

                        return array_values($node);
                    };
                } else {
                    $segments[] = static function (mixed $node) use ($key): array {
                        if (!is_array($node) || !array_key_exists($key, $node)) {
                            return [];
                        }

                        return [$node[$key]];
                    };
                }

                continue;
            }

            // Bracket accessor
            if ($path[$pos] === '[') {
                $pos++;
                $expr = self::readBracketExpr($path, $pos);
                $segments[] = self::compileBracket($expr);

                continue;
            }

            throw new InvalidArgumentException("Unexpected character '{$path[$pos]}' at position $pos");
        }

        return $segments;
    }

    private static function readKey(string $path, int &$pos): string
    {
        $start = $pos;
        $len = strlen($path);

        while ($pos < $len && $path[$pos] !== '.' && $path[$pos] !== '[') {
            $pos++;
        }

        $key = substr($path, $start, $pos - $start);

        if ($key === '') {
            throw new InvalidArgumentException("Empty key at position $start");
        }

        return $key;
    }

    private static function readBracketExpr(string $path, int &$pos): string
    {
        $depth = 1;
        $start = $pos;
        $len = strlen($path);
        $inString = false;
        $escaped = false;

        while ($pos < $len && $depth > 0) {
            $char = $path[$pos];

            if ($escaped) {
                $escaped = false;
                $pos++;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $pos++;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString = !$inString;
                $pos++;
                continue;
            }

            if (!$inString) {
                if ($char === '[') {
                    $depth++;
                } elseif ($char === ']') {
                    $depth--;
                }
            }

            if ($depth > 0) {
                $pos++;
            }
        }

        if ($depth !== 0) {
            throw new InvalidArgumentException('Unclosed bracket expression');
        }

        $expr = substr($path, $start, $pos - $start);
        $pos++; // skip closing ]

        return $expr;
    }

    /**
     * @return Closure(mixed): list<mixed>
     */
    private static function compileBracket(string $expr): Closure
    {
        $expr = trim($expr);

        // Wildcard: [*]
        if ($expr === '*') {
            return static function (mixed $node): array {
                if (!is_array($node)) {
                    return [];
                }

                return array_values($node);
            };
        }

        // Filter: [?(@.price < 10)]
        if (str_starts_with($expr, '?')) {
            return self::compileFilter(substr($expr, 1));
        }

        // Slice: [0:3], [::2], [-2:]
        if (str_contains($expr, ':')) {
            return self::compileSlice($expr);
        }

        // Quoted key: ['key'] or ["key"]
        if (preg_match('/^[\'"](.+)[\'"]$/', $expr, $m) === 1) {
            $key = $m[1];

            return static function (mixed $node) use ($key): array {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    return [];
                }

                return [$node[$key]];
            };
        }

        // Numeric index (supports negative)
        if (is_numeric($expr)) {
            $index = (int) $expr;

            return static function (mixed $node) use ($index): array {
                if (!is_array($node)) {
                    return [];
                }

                $values = array_values($node);
                $count = count($values);

                $resolved = $index < 0 ? $count + $index : $index;

                if ($resolved < 0 || $resolved >= $count) {
                    return [];
                }

                return [$values[$resolved]];
            };
        }

        // String key without quotes
        return static function (mixed $node) use ($expr): array {
            if (!is_array($node) || !array_key_exists($expr, $node)) {
                return [];
            }

            return [$node[$expr]];
        };
    }

    /**
     * @return Closure(mixed): list<mixed>
     */
    private static function compileFilter(string $filterExpr): Closure
    {
        $filterExpr = trim($filterExpr);

        // Remove outer parentheses if present
        if (str_starts_with($filterExpr, '(') && substr($filterExpr, -1) === ')') {
            $filterExpr = trim(substr($filterExpr, 1, -1));
        }

        // Parse: @.field op value
        if (preg_match('/^@\.(\w+)\s*(<=|>=|==|!=|<|>)\s*(.+)$/', $filterExpr, $m) === 1) {
            $field = $m[1];
            $op = $m[2];
            $rawValue = trim($m[3]);

            // Parse the comparison value
            $compareValue = self::parseValue($rawValue);

            return static function (mixed $node) use ($field, $op, $compareValue): array {
                if (!is_array($node)) {
                    return [];
                }

                $results = [];

                foreach ($node as $item) {
                    if (!is_array($item) || !array_key_exists($field, $item)) {
                        continue;
                    }

                    $val = $item[$field];

                    $match = match ($op) {
                        '==' => $val == $compareValue,
                        '!=' => $val != $compareValue,
                        '<' => $val < $compareValue,
                        '>' => $val > $compareValue,
                        '<=' => $val <= $compareValue,
                        '>=' => $val >= $compareValue,
                    };

                    if ($match) {
                        $results[] = $item;
                    }
                }

                return $results;
            };
        }

        // Existence check: @.field
        if (preg_match('/^@\.(\w+)$/', $filterExpr, $m) === 1) {
            $field = $m[1];

            return static function (mixed $node) use ($field): array {
                if (!is_array($node)) {
                    return [];
                }

                $results = [];

                foreach ($node as $item) {
                    if (is_array($item) && array_key_exists($field, $item)) {
                        $results[] = $item;
                    }
                }

                return $results;
            };
        }

        throw new InvalidArgumentException("Unsupported filter expression: $filterExpr");
    }

    /**
     * @return Closure(mixed): list<mixed>
     */
    private static function compileSlice(string $expr): Closure
    {
        $parts = explode(':', $expr);
        $start = trim($parts[0] ?? '') !== '' ? (int) $parts[0] : null;
        $end = isset($parts[1]) && trim($parts[1]) !== '' ? (int) $parts[1] : null;
        $step = isset($parts[2]) && trim($parts[2]) !== '' ? (int) $parts[2] : 1;

        if ($step === 0) {
            throw new InvalidArgumentException('Slice step cannot be zero');
        }

        return static function (mixed $node) use ($start, $end, $step): array {
            if (!is_array($node)) {
                return [];
            }

            $values = array_values($node);
            $count = count($values);

            $resolvedStart = $start ?? ($step > 0 ? 0 : $count - 1);
            $resolvedEnd = $end ?? ($step > 0 ? $count : -$count - 1);

            if ($resolvedStart < 0) {
                $resolvedStart = max(0, $count + $resolvedStart);
            }

            if ($resolvedEnd < 0) {
                $resolvedEnd = max(0, $count + $resolvedEnd);
            }

            $resolvedEnd = min($resolvedEnd, $count);

            $results = [];

            if ($step > 0) {
                for ($i = $resolvedStart; $i < $resolvedEnd; $i += $step) {
                    $results[] = $values[$i];
                }
            } else {
                for ($i = $resolvedStart; $i > $resolvedEnd; $i += $step) {
                    if ($i >= 0 && $i < $count) {
                        $results[] = $values[$i];
                    }
                }
            }

            return $results;
        };
    }

    /**
     * @return list<mixed>
     */
    private static function recursiveDescend(mixed $node, string $key): array
    {
        $results = [];

        if (!is_array($node)) {
            return $results;
        }

        if ($key === '*') {
            $results = [...$results, ...array_values($node)];
        } elseif (array_key_exists($key, $node)) {
            $results[] = $node[$key];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $results = [...$results, ...self::recursiveDescend($child, $key)];
            }
        }

        return $results;
    }

    private static function parseValue(string $raw): mixed
    {
        // Quoted string
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $raw, $m) === 1) {
            return $m[1];
        }

        $raw = ltrim($raw);

        if ($raw === 'true') {
            return true;
        }

        if ($raw === 'false') {
            return false;
        }

        if ($raw === 'null') {
            return null;
        }

        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }
}
