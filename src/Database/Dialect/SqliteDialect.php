<?php

declare(strict_types=1);

namespace Pulsar\Database\Dialect;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;
use Pulsar\Database\LockMode;

use function implode;
use function sprintf;

/**
 * SQLite.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SqliteDialect extends AbstractDialect
{
    #[Override]
    public function driver(): Driver
    {
        return Driver::SQLite;
    }

    /**
     * SQLite takes no row-level locks at all.
     *
     * It serialises writers at the database level instead, so there is nothing for
     * `FOR UPDATE` to express. Reported rather than silently ignored: a lock clause that
     * compiles to nothing is indistinguishable from one that was never asked for, and a
     * transaction proceeding in the belief that it holds a row lock is exactly the
     * failure this method exists to prevent.
     */
    #[Override]
    public function supportsRowLocking(): bool
    {
        return false;
    }

    #[Override]
    protected function lockClause(LockMode $mode): string
    {
        return '';
    }

    #[Override]
    public function currentTimestamp(): string
    {
        return "datetime('now')";
    }

    #[Override]
    public function compileBooleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    #[Override]
    public function supportsReturning(): bool
    {
        return true;
    }

    #[Override]
    public function supportsIndexIfNotExists(): bool
    {
        return true;
    }

    /**
     * `sqlite_master` rather than `pragma_index_list`, because the pragma takes the table
     * name as an argument and cannot be given one through a binding.
     */
    #[Override]
    public function compileIndexExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM sqlite_master '
            . "WHERE type = 'index' AND tbl_name = :table AND name = :index";
    }

    #[Override]
    public function compileUpsert(string $insertSql, array $conflictColumns, array $updateColumns): string
    {
        $conflict = [];

        foreach ($conflictColumns as $column) {
            $conflict[] = $this->quoteIdentifier($column);
        }

        $updates = [];

        foreach ($updateColumns as $column) {
            $quoted = $this->quoteIdentifier($column);
            // Lower-case `excluded` is SQLite's spelling; PostgreSQL uses `EXCLUDED`.
            $updates[] = sprintf('%s = excluded.%s', $quoted, $quoted);
        }

        return $insertSql
            . sprintf(' ON CONFLICT (%s) DO UPDATE SET ', implode(', ', $conflict))
            . implode(', ', $updates);
    }

    #[Override]
    public function name(): string
    {
        return 'sqlite';
    }
}
