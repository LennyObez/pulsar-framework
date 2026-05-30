<?php

declare(strict_types=1);

namespace Pulsar\Support;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function chr;
use function implode;
use function lcfirst;
use function mb_strtolower;
use function mb_substr;
use function ord;
use function preg_match;
use function preg_replace;
use function preg_split;
use function random_bytes;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;
use function ucfirst;

/**
 * Fluent string manipulation utilities.
 *
 * All methods are pure and static: no mutable state.
 * @api
 */
#[Api(since: '1.0.0')]
final class Str
{
    /**
     * Convert a string to a URL-friendly slug.
     *
     * Handles unicode by transliterating to ASCII-safe characters,
     * lowercases, and replaces non-alphanumeric runs with a separator.
     */
    #[NoDiscard]
    public static function slug(string $value, string $separator = '-'): string
    {
        // Replace non-alphanumeric characters with separator
        $slug = (string) preg_replace('/[^a-zA-Z0-9]+/', $separator, $value);

        // Remove leading/trailing separators
        return mb_strtolower(trim($slug, $separator));
    }

    /**
     * Convert a string to camelCase.
     */
    #[NoDiscard]
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * Convert a string to snake_case.
     */
    #[NoDiscard]
    public static function snake(string $value, string $delimiter = '_'): string
    {
        // Insert delimiter before uppercase letters
        $result = (string) preg_replace('/([a-z\d])([A-Z])/', '$1' . $delimiter . '$2', $value);
        $result = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1' . $delimiter . '$2', $result);

        // Replace spaces, hyphens, and multiple delimiters
        $result = (string) preg_replace('/[\s\-_]+/', $delimiter, $result);

        return mb_strtolower(trim($result, $delimiter));
    }

    /**
     * Convert a string to StudlyCase (PascalCase).
     */
    #[NoDiscard]
    public static function studly(string $value): string
    {
        /** @var list<string> $words */
        $words = preg_split('/[\s\-_]+/', $value) ?: [$value];

        return implode('', array_map(static fn(string $word): string => ucfirst(mb_strtolower($word)), $words));
    }

    /**
     * Check if a string contains a substring.
     */
    public static function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    /**
     * Check if a string starts with a substring.
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    /**
     * Check if a string ends with a substring.
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    /**
     * Get the substring before the first occurrence of a delimiter.
     *
     * Returns the original string if the delimiter is not found.
     */
    #[NoDiscard]
    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        return $pos === false ? $subject : substr($subject, 0, $pos);
    }

    /**
     * Get the substring after the first occurrence of a delimiter.
     *
     * Returns the original string if the delimiter is not found.
     */
    #[NoDiscard]
    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        return $pos === false ? $subject : substr($subject, $pos + strlen($search));
    }

    /**
     * Get the substring after the last occurrence of a delimiter.
     *
     * Returns the original string if the delimiter is not found.
     */
    #[NoDiscard]
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strrpos($subject, $search);

        return $pos === false ? $subject : substr($subject, $pos + strlen($search));
    }

    /**
     * Truncate a string to a maximum length, appending an ellipsis.
     */
    #[NoDiscard]
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return trim(mb_substr($value, 0, $limit)) . $end;
    }

    /**
     * Generate a UUID v4.
     */
    #[NoDiscard]
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // version 4
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // variant RFC 4122

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Check if a string matches a pattern (supports * wildcard).
     */
    public static function is(string $pattern, string $value): bool
    {
        if ($pattern === $value) {
            return true;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '/'));

        return (bool) preg_match('/^' . $regex . '$/', $value);
    }

    /**
     * Convert a string to kebab-case.
     */
    #[NoDiscard]
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }
}
