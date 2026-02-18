<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Pulsar\Api\Api;

use function array_map;
use function in_array;
use function preg_match;
use function preg_quote;
use function strtolower;

/**
 * Determines whether a query should be excluded from caching based on
 * sensitivity rules: regulated tables and authorization-scoped queries.
 */
#[Api(since: '1.0.0')]
final readonly class SensitivityMetadata
{
    public function __construct(
        private QueryCacheConfig $config,
    ) {}

    /**
     * Check if a table is marked as sensitive and must never be cached.
     */
    public function isTableSensitive(string $tableName): bool
    {
        return in_array(strtolower($tableName), $this->normalizedSensitiveTableNames(), true);
    }

    /**
     * Detect queries scoped by authorization columns (e.g., WHERE user_id = ?).
     *
     * @param array<string, mixed> $bindings
     */
    public function isAuthorizationShaped(string $sql, array $bindings): bool
    {
        foreach ($this->config->authorizationColumns as $column) {
            $pattern = '/\bWHERE\b.*\b' . preg_quote($column, '/') . '\b\s*[=<>!]/i';

            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Combined check: returns false if any table is sensitive or query is authorization-shaped.
     *
     * @param array<string, mixed> $bindings
     * @param list<string> $tables
     */
    public function shouldCache(string $sql, array $bindings, array $tables): bool
    {
        if (array_any($tables, fn(string $table): bool => $this->isTableSensitive($table))) {
            return false;
        }

        if ($this->isAuthorizationShaped($sql, $bindings)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function normalizedSensitiveTableNames(): array
    {
        return array_map(strtolower(...), $this->config->sensitiveTableNames);
    }
}
