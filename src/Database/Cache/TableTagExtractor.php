<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Pulsar\Api\Api;

use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function preg_match_all;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * Extracts table names from SQL queries for use as cache invalidation tags.
 *
 * Uses regex-based extraction from SQL text to identify referenced tables.
 * Returns lowercase, deduplicated table names suitable for tag-based invalidation.
 */
#[Api(since: '1.0.0')]
final readonly class TableTagExtractor
{
    /**
     * Extract table names from a SQL query string.
     *
     * Parses FROM, JOIN, INTO, UPDATE, and DELETE FROM clauses to identify
     * all tables referenced by the query.
     *
     * @param array<string, mixed>|null $queryBuilderContext Reserved for future query builder integration
     * @return list<string> Lowercase table names
     */
    public function extractTags(string $sql, ?array $queryBuilderContext = null): array
    {
        $tables = [];

        $tables = array_merge($tables, $this->extractFromClauses($sql));
        $tables = array_merge($tables, $this->extractJoinClauses($sql));
        $tables = array_merge($tables, $this->extractInsertInto($sql));
        $tables = array_merge($tables, $this->extractUpdate($sql));
        $tables = array_merge($tables, $this->extractDeleteFrom($sql));

        return array_values(array_unique($tables));
    }

    /**
     * @return list<string>
     */
    private function extractFromClauses(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\bFROM\s+([`"\[]?\w+[`"\]]?(?:\s*,\s*[`"\[]?\w+[`"\]]?)*)/i', $sql, $matches)) {
            foreach ($matches[1] as $match) {
                foreach (explode(',', $match) as $table) {
                    $tables[] = $this->cleanTableName(trim($table));
                }
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function extractJoinClauses(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\bJOIN\s+([`"\[]?\w+[`"\]]?)/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = $this->cleanTableName($table);
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function extractInsertInto(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\bINTO\s+([`"\[]?\w+[`"\]]?)/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = $this->cleanTableName($table);
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function extractUpdate(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\bUPDATE\s+([`"\[]?\w+[`"\]]?)/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = $this->cleanTableName($table);
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function extractDeleteFrom(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\bDELETE\s+FROM\s+([`"\[]?\w+[`"\]]?)/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = $this->cleanTableName($table);
            }
        }

        return $tables;
    }

    private function cleanTableName(string $name): string
    {
        $cleaned = preg_replace('/[`"\[\]\s]/', '', $name) ?? $name;

        return strtolower($cleaned);
    }
}
