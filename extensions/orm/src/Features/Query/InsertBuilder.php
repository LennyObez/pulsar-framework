<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function array_keys;
use function is_scalar;

/**
 * Internal INSERT builder: not exposed on the public API.
 *
 * All writes go through repositories with MutationContext.
 */
#[Internal]
final class InsertBuilder
{
    private readonly IdentifierQuoter $quoter;
    private readonly BindingCounter $bindingCounter;

    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly ?string $primaryKeyColumn = null,
    ) {
        $this->quoter = new IdentifierQuoter($connection->driver());
        $this->bindingCounter = new BindingCounter();
    }

    /**
     * @param array<string, mixed> $values Column => value map
     */
    public function values(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    /**
     * Execute the INSERT and return the last insert ID.
     */
    public function execute(): string
    {
        $columns = array_keys($this->values);
        $placeholders = [];
        $bindings = [];

        /** @var mixed $value */
        foreach ($this->values as $value) {
            $binding = $this->bindingCounter->next();
            $placeholders[] = ':' . $binding;
            $bindings = [...$bindings, $binding => $value];
        }

        $compiler = new SqlCompiler($this->connection->driver());
        $returning = $this->primaryKeyColumn !== null && $compiler->dialect()->supportsReturning()
            ? $this->primaryKeyColumn
            : null;

        $sql = $compiler->compileInsert(
            $this->quoter->quote($this->table),
            $columns,
            $placeholders,
            $returning,
        );

        // On dialects that support RETURNING, the inserted primary key is read
        // straight from the statement's result set. This is authoritative even
        // when INSERT triggers touch other sequences in the same session, where
        // lastInsertId() returns the wrong sequence's value. The INSERT runs as
        // part of this query, so no separate execute() is issued.
        if ($returning !== null) {
            $result = $this->connection->query($sql, $bindings);
            $row = $result->rows[0] ?? null;

            if ($row !== null) {
                /** @var mixed $returnedId */
                $returnedId = $row->get($returning);

                if (is_scalar($returnedId)) {
                    return (string) $returnedId;
                }
            }

            return $this->connection->lastInsertId();
        }

        $this->connection->execute($sql, $bindings);

        return $this->connection->lastInsertId();
    }
}
