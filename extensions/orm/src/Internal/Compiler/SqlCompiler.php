<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Compiler;

use Pulsar\Api\Internal;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function array_map;
use function implode;
use function sprintf;

/**
 * Compiles SQL statements from query builder state.
 */
#[Internal]
final readonly class SqlCompiler
{
    private IdentifierQuoter $quoter;
    private DialectInterface $dialect;

    public function __construct(Driver $driver)
    {
        $this->quoter = new IdentifierQuoter($driver);
        $this->dialect = $this->quoter->dialect();
    }

    /**
     * Compile a SELECT statement.
     *
     * @param list<string> $columns Raw column expressions (already quoted or aliased)
     * @param string $from Table + alias expression
     * @param list<string> $joins Compiled JOIN clauses
     * @param list<string> $wheres Compiled WHERE conditions
     * @param list<string> $groupBy Compiled GROUP BY columns
     * @param list<string> $havings Compiled HAVING conditions
     * @param list<string> $orderBy Compiled ORDER BY expressions
     */
    public function compileSelect(
        array $columns,
        string $from,
        array $joins = [],
        array $wheres = [],
        array $groupBy = [],
        array $havings = [],
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null,
        LockMode $lock = LockMode::None,
    ): string {
        $sql = 'SELECT ' . implode(', ', $columns);
        $sql .= ' FROM ' . $from;

        if ($joins !== []) {
            $sql .= ' ' . implode(' ', $joins);
        }

        if ($wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        if ($groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $groupBy);
        }

        if ($havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $havings);
        }

        if ($orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $orderBy);
        }

        $sql .= $this->dialect->compileLimitOffset($limit, $offset);
        $sql .= $this->dialect->compileLock($lock);

        return $sql;
    }

    /**
     * Compile an INSERT statement.
     *
     * @param string $table Quoted table name
     * @param list<string> $columns Column names (will be quoted)
     * @param list<string> $placeholders Binding placeholders
     * @param ?string $returningColumn Primary-key column to read back via
     *                RETURNING on dialects that support it; null omits the clause
     */
    public function compileInsert(string $table, array $columns, array $placeholders, ?string $returningColumn = null): string
    {
        $quotedColumns = array_map(fn(string $col): string => $this->quoter->quote($col), $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $quotedColumns),
            implode(', ', $placeholders),
        );

        if ($returningColumn !== null && $this->dialect->supportsReturning()) {
            $sql .= sprintf(' RETURNING %s', $this->quoter->quote($returningColumn));
        }

        return $sql;
    }

    /**
     * Compile an UPDATE statement.
     *
     * @param string $table Quoted table name
     * @param list<string> $setClauses "column = :placeholder" pairs
     * @param list<string> $wheres WHERE conditions
     */
    public function compileUpdate(string $table, array $setClauses, array $wheres = []): string
    {
        $sql = sprintf('UPDATE %s SET %s', $table, implode(', ', $setClauses));

        if ($wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        return $sql;
    }

    /**
     * Compile a DELETE statement.
     *
     * @param string $table Quoted table name
     * @param list<string> $wheres WHERE conditions
     */
    public function compileDelete(string $table, array $wheres = []): string
    {
        $sql = sprintf('DELETE FROM %s', $table);

        if ($wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        return $sql;
    }

    public function quoter(): IdentifierQuoter
    {
        return $this->quoter;
    }

    public function dialect(): DialectInterface
    {
        return $this->dialect;
    }
}
