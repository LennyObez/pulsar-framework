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
