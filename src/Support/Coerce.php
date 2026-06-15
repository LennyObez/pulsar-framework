<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function strtolower;

/**
 * Type-coercion helpers for configuration DTO `fromArray` boundaries.
 *
 * Use these from `fromArray` static factories that accept untyped raw arrays
 * (typically loaded from config files or HTTP input) to narrow individual
 * fields into the strict types declared by the DTO constructor.
 *
 * Each method follows the same contract: if `$value` matches the target
 * type (or is losslessly convertible from a sister type — e.g. a numeric
 * string for an int field), return the converted value; otherwise return
 * the supplied default. No exception is ever thrown.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class Coerce
{
    public static function int(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Strict int: only an actual int returns; numeric strings, floats, and
     * everything else fall back to default. Use when the config schema
     * intentionally rejects loose typing.
     */
    public static function strictInt(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * Raw-input int coercion with PHP `(int)`-cast semantics for present
     * values: an omitted key (passed as null) keeps the schema `$absentDefault`,
     * but a key that is *present yet non-numeric* (e.g. `'invalid'`, `false`,
     * `[]`) collapses to `0` rather than silently reverting to the default.
     * This distinguishes "not configured" from "configured with garbage".
     */
    public static function intFromInput(mixed $value, int $absentDefault): int
    {
        return $value === null ? $absentDefault : self::int($value, 0);
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Nullable int: numeric values (int, float, numeric string) coerce to int;
     * anything else — including a present-but-non-numeric value — yields null.
     * Use for optional `?int` fields where invalid input must not fabricate a 0.
     */
    public static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Nullable float counterpart to {@see nullableInt}: numeric values coerce to
     * float; present-but-non-numeric input yields null rather than 0.0.
     */
    public static function nullableFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Raw-input string counterpart to {@see intFromInput}: an omitted key (null)
     * keeps `$absentDefault`; a value that is present but not a string collapses
     * to the empty string `''` rather than reverting to the default.
     */
    public static function stringFromInput(mixed $value, string $absentDefault = ''): string
    {
        return $value === null ? $absentDefault : self::string($value, '');
    }

    /**
     * Loose bool coercion: numeric 0/1, "true"/"false"/"yes"/"no"/"on"/"off" strings
     * are recognized. Use for human-edited config where forgiving parsing is desired.
     */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', 'yes', '1', 'on'], true);
        }

        return $default;
    }

    /**
     * Strict bool: only an actual bool returns; everything else falls back to default.
     * Use when an int/string would mean a programming error rather than a typo.
     */
    public static function strictBool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    public static function float(mixed $value, float $default): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * Raw-input float counterpart to {@see intFromInput}: an omitted key (null)
     * keeps `$absentDefault`; a present-but-non-numeric value collapses to `0.0`.
     */
    public static function floatFromInput(mixed $value, float $absentDefault): float
    {
        return $value === null ? $absentDefault : self::float($value, 0.0);
    }

    /**
     * Strict float: only an actual float returns; ints, numeric strings, and
     * everything else fall back to default. Use when the config schema
     * intentionally rejects loose typing (mirrors {@see strictInt}).
     */
    public static function strictFloat(mixed $value, float $default): float
    {
        return is_float($value) ? $value : $default;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    public static function listOfString(mixed $value, array $default = []): array
    {
        if (!is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * Raw-input string-list coercion: scalars are wrapped (`'a'` → `['a']`),
     * then every element that is not a non-empty string is dropped. Used for
     * config lists of identifiers/IPs/names where blanks and wrong-typed
     * entries are meaningless. An omitted key (null) yields an empty list.
     *
     * @return list<string>
     */
    public static function stringListFromInput(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            $items,
            static fn(mixed $v): bool => is_string($v) && $v !== '',
        ));
    }

    /**
     * Lenient string-list coercion that preserves arity: each element of an
     * array becomes itself if it is a string, otherwise the empty string `''`
     * (positions are kept, nothing is dropped). A non-array value yields
     * `$default`. Use where the list index is significant or blanks must stay
     * visible, in contrast to {@see stringListFromInput} which drops non-strings.
     *
     * @param list<string> $default
     * @return list<string>
     */
    public static function stringListOrEmpty(mixed $value, array $default = []): array
    {
        if (!is_array($value)) {
            return $default;
        }

        return array_values(array_map(
            static fn(mixed $v): string => is_string($v) ? $v : '',
            $value,
        ));
    }

    /**
     * @param list<int> $default
     * @return list<int>
     */
    public static function listOfInt(mixed $value, array $default = []): array
    {
        if (!is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, is_int(...)));
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public static function mapOfMixed(mixed $value, array $default = []): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $filtered = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $filtered[$k] = $v;
            }
        }

        return $filtered;
    }
}
