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

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
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
