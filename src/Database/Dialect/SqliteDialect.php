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
     * `sqlite_master` rather than `pragma_index_list`, because it answers about the index
     * and its owning table in one predicate; the pragma lists a table's indexes and would
     * need the table established separately.
     *
     * Both take a binding in their table-valued form. It is the bare statement form,
     * `PRAGMA index_list(:table)`, that does not — measured against SQLite 3.53.2, where
     * it is a syntax error at the placeholder.
     */
    #[Override]
    public function compileIndexExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM sqlite_master '
            . "WHERE type = 'index' AND tbl_name = :table AND name = :index";
    }

    #[Override]
    public function compileTableExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM sqlite_master '
            . "WHERE type = 'table' AND name = :table";
    }

    /**
     * `pragma_table_info` in its table-valued form, which takes the binding. A table that
     * does not exist yields no rows rather than an error, so absence counts as zero
     * without a special case.
     */
    #[Override]
    public function compileColumnExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM pragma_table_info(:table) WHERE name = :column';
    }

    /**
     * `pk` is the 1-based position of a column within the primary key and 0 for columns
     * outside it, so any positive count means a key exists.
     */
    #[Override]
    public function compilePrimaryKeyExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM pragma_table_info(:table) WHERE pk > 0';
    }

    /**
     * `rowid` is the identity: every ordinary table has one, and `MIN` over it picks the
     * earliest copy, so the row that survives is the one that was there first.
     *
     * A `WITHOUT ROWID` table has none, and this statement would fail on it. Such a table
     * already carries a primary key by definition — that is the only way to declare one —
     * so it cannot hold the duplicates this is for.
     */
    #[Override]
    public function compileCollapseDuplicates(
        string $table,
        array $keyColumns,
        ?string $discriminator = null,
    ): string {
        $quoted = $this->quoteIdentifier($table);

        $key = [];

        foreach ($keyColumns as $column) {
            $key[] = $this->quoteIdentifier($column);
        }

        // The discriminator is accepted and unused: SQLite always has a row identity, so
        // it never needs one, and MIN(rowid) keeps the copy that was inserted first.
        return sprintf(
            'DELETE FROM %s WHERE rowid NOT IN (SELECT MIN(rowid) FROM %s GROUP BY %s)',
            $quoted,
            $quoted,
            implode(', ', $key),
        );
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
