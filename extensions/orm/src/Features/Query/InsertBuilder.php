<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function array_keys;

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
        $sql = $compiler->compileInsert(
            $this->quoter->quote($this->table),
            $columns,
            $placeholders,
        );

        $this->connection->execute($sql, $bindings);

        return $this->connection->lastInsertId();
    }
}
