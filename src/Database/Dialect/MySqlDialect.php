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
 * MySQL.
 *
 * MariaDB connects through the same PDO driver but is not this dialect — see
 * {@see MariaDbDialect} for what the two disagree about.
 *
 * @api
 */
#[Api(since: '1.0.0')]
readonly class MySqlDialect extends AbstractDialect
{
    #[Override]
    public function driver(): Driver
    {
        return Driver::MySQL;
    }

    #[Override]
    protected function lockClause(LockMode $mode): string
    {
        return match ($mode) {
            LockMode::ForUpdate => ' FOR UPDATE',
            // MySQL 8.0 added `FOR SHARE`, but `LOCK IN SHARE MODE` is accepted by both
            // 5.7 and 8.x, so it is what the framework emits.
            LockMode::ForShare => ' LOCK IN SHARE MODE',
            LockMode::None => '',
        };
    }

    #[Override]
    public function currentTimestamp(): string
    {
        return 'NOW()';
    }

    #[Override]
    public function compileBooleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    #[Override]
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * MySQL has never accepted `IF NOT EXISTS` on `CREATE INDEX`.
     *
     * Not a degradation but a parse error, and the one engine difference the migration
     * corpus trips over most often — MariaDB added the clause, stock MySQL did not.
     */
    #[Override]
    public function supportsIndexIfNotExists(): bool
    {
        return false;
    }

    /**
     * `IF NOT EXISTS` is silently dropped here because MySQL has no way to say it.
     *
     * The alternative would be to throw when a caller asks for it, which would make every
     * portable migration branch on the engine again — the thing this interface exists to
     * stop. So the request is honoured where it can be and ignored where it cannot, and
     * {@see supportsIndexIfNotExists()} is how a caller finds out which happened.
     */
    #[Override]
    public function compileCreateIndex(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        bool $ifNotExists = false,
    ): string {
        return parent::compileCreateIndex($name, $table, $columns, $unique, false);
    }

    /**
     * MySQL drops an index through the table that owns it, and accepts no `IF EXISTS`.
     */
    #[Override]
    public function compileDropIndex(string $name, string $table): string
    {
        return sprintf(
            'DROP INDEX %s ON %s',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($table),
        );
    }

    /**
     * Scoped to the connected schema by `DATABASE()`: index names are unique per table,
     * not per server, so an unscoped count would answer for somebody else's database.
     */
    #[Override]
    public function compileIndexExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index';
    }

    /**
     * `DATABASE()` scopes every one of these to the connected schema. Unscoped, they
     * answer for whichever database on the server happens to hold a same-named table —
     * and on a shared server that is somebody else's.
     */
    #[Override]
    public function compileTableExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :table';
    }

    #[Override]
    public function compileColumnExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column';
    }

    #[Override]
    public function compilePrimaryKeyExists(): string
    {
        return 'SELECT COUNT(*) AS c FROM information_schema.table_constraints '
            . 'WHERE table_schema = DATABASE() AND table_name = :table '
            . "AND constraint_type = 'PRIMARY KEY'";
    }

    /**
     * With a discriminator, a self-join: MySQL refuses to read the target table in a
     * subquery of its own `DELETE`, so the survivor is chosen by joining the table to
     * itself and deleting every row another row beats.
     *
     * Without one, null. MySQL exposes no stable per-row identity, so two rows that agree
     * on every column cannot be told apart by any predicate and no `DELETE` can keep
     * exactly one of them. The caller rebuilds from a grouped read instead. That is the
     * honest answer: not "this engine is MySQL", but "this engine cannot express it".
     */
    #[Override]
    public function compileCollapseDuplicates(
        string $table,
        array $keyColumns,
        ?string $discriminator = null,
    ): ?string {
        if ($discriminator === null) {
            return null;
        }

        $quoted = $this->quoteIdentifier($table);

        $predicates = [];

        foreach ($keyColumns as $column) {
            $identifier = $this->quoteIdentifier($column);
            $predicates[] = sprintf('keep.%s = dupe.%s', $identifier, $identifier);
        }

        $ordering = $this->quoteIdentifier($discriminator);
        $predicates[] = sprintf('keep.%s < dupe.%s', $ordering, $ordering);

        return sprintf(
            'DELETE dupe FROM %s dupe JOIN %s keep ON %s',
            $quoted,
            $quoted,
            implode(' AND ', $predicates),
        );
    }

    #[Override]
    public function compileUpsert(string $insertSql, array $conflictColumns, array $updateColumns): string
    {
        $updates = [];

        foreach ($updateColumns as $column) {
            $quoted = $this->quoteIdentifier($column);
            $updates[] = sprintf('%s = VALUES(%s)', $quoted, $quoted);
        }

        // The conflict target is not named: MySQL infers it from whichever unique index
        // the insert violated, which is why `$conflictColumns` goes unused here and is
        // required by every other engine.
        return $insertSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    #[Override]
    public function name(): string
    {
        return 'mysql';
    }
}
