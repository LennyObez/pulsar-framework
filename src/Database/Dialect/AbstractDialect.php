<?php

declare(strict_types=1);

namespace Pulsar\Database\Dialect;

use Pulsar\Api\Internal;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\LockMode;
use Pulsar\Database\SqlIdentifier;

use function implode;
use function sprintf;

/**
 * The parts of a dialect that every supported engine happens to agree on.
 *
 * Only genuine agreement belongs here. A default that most engines share but one does not
 * is worse than no default at all: the odd engine out inherits a silently wrong answer,
 * and the override that should have existed is missing rather than visibly absent. Every
 * method below is either identical across all four engines or delegates to a single
 * authority.
 */
#[Internal]
abstract readonly class AbstractDialect implements DialectInterface
{
    /**
     * Identifier delimiting is the same problem for every engine and is answered in one
     * place, so a fifth engine cannot arrive with a fifth opinion about it.
     */
    public function quoteIdentifier(string $identifier): string
    {
        return SqlIdentifier::quote($identifier, $this->driver());
    }

    /**
     * `LIMIT n OFFSET m` is common to MySQL, MariaDB, PostgreSQL and SQLite. It is not
     * universal SQL — SQL Server writes `OFFSET … FETCH` — so an engine that spells it
     * differently overrides rather than inherits.
     */
    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d', $limit);
        }

        if ($offset !== null && $offset > 0) {
            $sql .= sprintf(' OFFSET %d', $offset);
        }

        return $sql;
    }

    public function variant(): DriverVariant
    {
        return DriverVariant::Standard;
    }

    /**
     * The standard spelling, shared by PostgreSQL, SQLite and MariaDB. MySQL overrides it
     * because it is the one engine that never gained `IF NOT EXISTS` here.
     */
    public function compileCreateIndex(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        bool $ifNotExists = false,
    ): string {
        $quoted = [];

        foreach ($columns as $column) {
            $quoted[] = $this->quoteIdentifier($column);
        }

        return sprintf(
            'CREATE %sINDEX %s%s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($table),
            implode(', ', $quoted),
        );
    }

    /**
     * The standard form drops an index by name alone. MySQL cannot, and overrides.
     */
    public function compileDropIndex(string $name, string $table): string
    {
        return sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($name));
    }

    /**
     * An engine that can lock rows says so and compiles the clause; one that cannot says
     * so and returns nothing. The default is the honest one for a dialect that has not
     * declared itself.
     */
    public function supportsRowLocking(): bool
    {
        return true;
    }

    public function compileLock(LockMode $mode): string
    {
        return $mode === LockMode::None ? '' : $this->lockClause($mode);
    }

    /**
     * The engine's spelling of a non-`None` lock mode.
     */
    abstract protected function lockClause(LockMode $mode): string;
}
