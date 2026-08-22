<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Pulsar\Api\Api;

use function array_keys;
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
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TableTagExtractor
{
    /**
     * Combined pattern matching all SQL clause types in a single pass.
     *
     * Matches: FROM, JOIN, INTO, UPDATE, DELETE FROM: each followed by a
     * quoted/unquoted table identifier. The FROM clause also handles
     * comma-separated table lists.
     */
    private const string TABLE_PATTERN = '/\b(?:DELETE\s+FROM|FROM|JOIN|INTO|UPDATE)\s+([`"\[]?\w+[`"\]]?(?:\s*,\s*[`"\[]?\w+[`"\]]?)*)/i';

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
        if (preg_match_all(self::TABLE_PATTERN, $sql, $matches) === 0) {
            return [];
        }

        $tables = [];

        foreach ($matches[1] as $match) {
            foreach (explode(',', $match) as $table) {
                $name = $this->cleanTableName(trim($table));
                $tables[$name] = true;
            }
        }

        return array_keys($tables);
    }

    private function cleanTableName(string $name): string
    {
        $cleaned = preg_replace('/[`"\[\]\s]/', '', $name) ?? $name;

        return strtolower($cleaned);
    }
}
