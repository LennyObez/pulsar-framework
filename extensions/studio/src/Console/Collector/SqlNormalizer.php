<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use NoDiscard;
use Pulsar\Api\Internal;

use function hash;
use function mb_strtolower;
use function preg_replace;
use function strtoupper;
use function trim;

/**
 * Normalizes SQL queries for safe storage and fingerprinting.
 *
 * Strips all literal values (numeric and string), collapses whitespace,
 * and lowercases SQL keywords. The normalized form is deterministic:
 * two structurally identical queries will always produce the same fingerprint.
 */
#[Internal]
final class SqlNormalizer
{
    /** @var list<string> SQL keywords to lowercase */
    private const array KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'INSERT', 'UPDATE', 'DELETE', 'JOIN',
        'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'ON', 'AND', 'OR',
        'IN', 'BETWEEN', 'LIKE', 'LIMIT', 'OFFSET', 'ORDER', 'GROUP',
        'HAVING', 'SET', 'VALUES', 'INTO', 'CREATE', 'ALTER', 'DROP',
        'INDEX', 'TABLE', 'AS', 'NOT', 'NULL', 'IS', 'EXISTS', 'DISTINCT',
        'UNION', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'ASC', 'DESC',
        'CASCADE', 'BY', 'IF', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT',
        'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'UNIQUE', 'CHECK',
        'DEFAULT', 'CONSTRAINT', 'ADD', 'COLUMN', 'AUTOINCREMENT',
        'INTEGER', 'TEXT', 'REAL', 'BLOB', 'PRAGMA', 'VACUUM', 'EXPLAIN',
        'WITH', 'RECURSIVE', 'REPLACE', 'IGNORE', 'CONFLICT', 'ABORT',
        'FAIL', 'IMMEDIATE', 'DEFERRED', 'EXCLUSIVE', 'TEMPORARY', 'TEMP',
        'VIEW', 'TRIGGER', 'ALL', 'ANY', 'SOME', 'TRUE', 'FALSE',
    ];

    /**
     * Normalize an SQL query: strip literals, collapse whitespace, lowercase keywords.
     */
    #[NoDiscard]
    public static function normalize(string $sql): string
    {
        // 1. Replace single-quoted string literals with ?
        $sql = preg_replace("/'.*?'/s", '?', $sql) ?? $sql;

        // 2. Replace numeric literals (standalone numbers, not inside identifiers)
        $sql = preg_replace('/\b\d+\.?\d*\b/', '?', $sql) ?? $sql;

        // 3. Collapse whitespace (newlines, tabs, multiple spaces → single space)
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        // 4. Trim
        $sql = trim($sql);

        // 5. Lowercase SQL keywords
        foreach (self::KEYWORDS as $keyword) {
            $pattern = '/\b' . $keyword . '\b/i';
            $sql = preg_replace($pattern, mb_strtolower($keyword), $sql) ?? $sql;
        }

        return $sql;
    }

    /**
     * Compute a SHA-256 fingerprint of the normalized SQL.
     */
    #[NoDiscard]
    public static function fingerprint(string $sql): string
    {
        return hash('sha256', self::normalize($sql));
    }

    /**
     * Detect the query type from the SQL prefix.
     */
    #[NoDiscard]
    public static function detectQueryType(string $sql): string
    {
        $trimmed = trim($sql);
        $firstWord = strtoupper(explode(' ', $trimmed, 2)[0] ?? '');

        return match ($firstWord) {
            'SELECT' => 'SELECT',
            'INSERT' => 'INSERT',
            'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
            'CREATE', 'ALTER', 'DROP' => 'DDL',
            default => 'OTHER',
        };
    }
}
