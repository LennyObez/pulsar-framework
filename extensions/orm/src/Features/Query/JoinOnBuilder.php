<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\QualifiedRef;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function implode;
use function sprintf;

/**
 * Builder for JOIN ON conditions.
 *
 * All column references must be qualified (alias.column) to avoid ambiguity.
 * @api
 */
#[Api(since: '1.0.0')]
final class JoinOnBuilder
{
    /** @var list<string> */
    private array $conditions = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    public function __construct(
        private readonly IdentifierQuoter $quoter,
        private readonly BindingCounter $bindingCounter,
    ) {}

    /**
     * Add an ON condition comparing two qualified column references.
     *
     * @throws \Pulsar\Extension\Orm\Exception\QueryBuilderException If the operator is not in the allowlist.
     */
    public function on(string $left, string $operator, string $right): self
    {
        $validatedOp = ExpressionCompiler::validateOperator($operator);
        $leftRef = QualifiedRef::parse($left);
        $rightRef = QualifiedRef::parse($right);

        $this->conditions[] = sprintf(
            '%s %s %s',
            $this->quoter->quote($leftRef->toString()),
            $validatedOp,
            $this->quoter->quote($rightRef->toString()),
        );

        return $this;
    }

    /**
     * Add an ON condition comparing a column to a bound value.
     *
     * @throws \Pulsar\Extension\Orm\Exception\QueryBuilderException If the operator is not in the allowlist.
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $validatedOp = ExpressionCompiler::validateOperator($operator);
        $ref = QualifiedRef::parse($column);
        $binding = $this->bindingCounter->next();

        $this->conditions[] = sprintf(
            '%s %s :%s',
            $this->quoter->quote($ref->toString()),
            $validatedOp,
            $binding,
        );
        $this->bindings[$binding] = $value;

        return $this;
    }

    /**
     * Compile the ON clause.
     *
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function compile(): array
    {
        return [
            'sql' => implode(' AND ', $this->conditions),
            'bindings' => $this->bindings,
        ];
    }
}
