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
 * Internal DELETE builder: not exposed on the public API.
 *
 * All writes go through repositories with MutationContext.
 */
#[Internal]
final class DeleteBuilder
{
    private readonly IdentifierQuoter $quoter;
    private readonly BindingCounter $bindingCounter;

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
     * Execute the DELETE and return the number of affected rows.
     */
    public function execute(): int
    {
        $compiler = new SqlCompiler($this->connection->driver());
        $sql = $compiler->compileDelete(
            $this->quoter->quote($this->table),
            $this->wheres,
        );

        return $this->connection->execute($sql, $this->bindings);
    }
}
