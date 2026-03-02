<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function sprintf;

/**
 * Internal UPDATE builder: not exposed on the public API.
 *
 * All writes go through repositories with MutationContext.
 */
#[Internal]
final class UpdateBuilder
{
    private readonly IdentifierQuoter $quoter;
    private readonly BindingCounter $bindingCounter;

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string> */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

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
    public function set(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    /**
     * Add a WHERE condition.
     */
    public function where(string $column, mixed $value): self
    {
        $binding = $this->bindingCounter->next('w');
        $this->wheres[] = sprintf('%s = :%s', $this->quoter->quote($column), $binding);
        $this->bindings[$binding] = $value;

        return $this;
    }

    /**
     * Execute the UPDATE and return the number of affected rows.
     */
    public function execute(): int
    {
        $setClauses = [];
        foreach ($this->values as $column => $value) {
            $binding = $this->bindingCounter->next('s');
            $setClauses[] = sprintf('%s = :%s', $this->quoter->quote($column), $binding);
            $this->bindings[$binding] = $value;
        }

        $compiler = new SqlCompiler($this->connection->driver());
        $sql = $compiler->compileUpdate(
            $this->quoter->quote($this->table),
            $setClauses,
            $this->wheres,
        );

        return $this->connection->execute($sql, $this->bindings);
    }
}
