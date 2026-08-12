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
 * PostgreSQL.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PostgreSqlDialect extends AbstractDialect
{
    #[Override]
    public function driver(): Driver
    {
        return Driver::PostgreSQL;
    }

    #[Override]
    protected function lockClause(LockMode $mode): string
    {
        return match ($mode) {
            LockMode::ForUpdate => ' FOR UPDATE',
            LockMode::ForShare => ' FOR SHARE',
            LockMode::None => '',
        };
    }

    #[Override]
    public function currentTimestamp(): string
    {
        return 'NOW()';
    }

    /**
     * PostgreSQL has a real boolean type and rejects `1` where one is expected.
     */
    #[Override]
    public function compileBooleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
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

    #[Override]
    public function supportsPartialIndexes(): bool
    {
        return true;
    }

    #[Override]
    public function supportsNullsOrdering(): bool
    {
        return true;
    }

    /**
     * Resolved through `to_regclass`, which answers for the one relation a bare name
     * reaches — exactly what the `CREATE INDEX` this guards will touch.
     *
     * The obvious spelling, `pg_indexes` filtered by `schemaname = ANY (current_schemas(
     * false))`, searches every schema on the path while the DDL resolves to one. On
     * PostgreSQL's unmodified default path (`"$user", public`), a role owning a same-named
     * schema is enough to make the guard answer for a different table — measured: the
     * caller returned success and created nothing. `to_regclass` yields NULL when there is
     * no such relation, and a comparison against NULL matches no rows, so absence needs no
     * special case.
     */
    #[Override]
    public function compileIndexExists(): string
    {
        // quote_ident, because to_regclass parses its argument as SQL rather than taking
        // it as a name: an unquoted `MyTable` folds to `mytable` and answers for a
        // different relation, or for none.
        return 'SELECT COUNT(*) AS c FROM pg_index x '
            . 'JOIN pg_class i ON i.oid = x.indexrelid '
            . 'WHERE x.indrelid = to_regclass(quote_ident(:table)) AND i.relname = :index';
    }

    /**
     * `to_regclass(quote_ident(...))` throughout, for the reason given above: it resolves
     * a bare name exactly as the DDL does, so the guard and the statement it guards can
     * never land on different schemas. It yields NULL when there is no such relation, and
     * a comparison against NULL matches no rows, so absence needs no special case.
     */
    #[Override]
    public function compileTableExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM pg_class WHERE oid = to_regclass(quote_ident(:table))';
    }

    /**
     * `attisdropped` and `attnum > 0` exclude the two kinds of entry that are not columns
     * a caller can name: one dropped but still occupying its slot, and the system columns
     * every relation carries at negative positions.
     */
    #[Override]
    public function compileColumnExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM pg_attribute '
            . 'WHERE attrelid = to_regclass(quote_ident(:table)) AND attname = :column '
            . 'AND NOT attisdropped AND attnum > 0';
    }

    #[Override]
    public function compilePrimaryKeyExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM pg_constraint '
            . "WHERE conrelid = to_regclass(quote_ident(:table)) AND contype = 'p'";
    }

    /**
     * `ctid` is the identity, and the comparison keeps the lowest — the physical position
     * of the earliest surviving copy. It is not stable across a `VACUUM FULL`, which is
     * why it is read and acted on inside one statement rather than collected first.
     */
    #[Override]
    public function compileCollapseDuplicates(
        string $table,
        array $keyColumns,
        ?string $discriminator = null,
    ): string {
        $quoted = $this->quoteIdentifier($table);

        $predicates = [];

        foreach ($keyColumns as $column) {
            $identifier = $this->quoteIdentifier($column);
            $predicates[] = sprintf('keep.%s = dupe.%s', $identifier, $identifier);
        }

        // A discriminator orders the duplicates on its own, so it is preferred over ctid,
        // which is a physical position and not stable across a VACUUM FULL.
        $ordering = $discriminator === null
            ? 'keep.ctid < dupe.ctid'
            : sprintf(
                'keep.%s < dupe.%s',
                $this->quoteIdentifier($discriminator),
                $this->quoteIdentifier($discriminator),
            );

        return sprintf(
            'DELETE FROM %s dupe USING %s keep WHERE %s AND %s',
            $quoted,
            $quoted,
            implode(' AND ', $predicates),
            $ordering,
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
            $updates[] = sprintf('%s = EXCLUDED.%s', $quoted, $quoted);
        }

        return $insertSql
            . sprintf(' ON CONFLICT (%s) DO UPDATE SET ', implode(', ', $conflict))
            . implode(', ', $updates);
    }

    #[Override]
    public function name(): string
    {
        return 'pgsql';
    }
}
