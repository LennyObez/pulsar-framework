<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use function implode;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\QualifiedRef;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function sprintf;

/**
 * Builder for JOIN ON conditions.
 *
 * All column references must be qualified (alias.column) to avoid ambiguity.
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
     */
    public function on(string $left, string $operator, string $right): self
    {
        $leftRef = QualifiedRef::parse($left);
        $rightRef = QualifiedRef::parse($right);

        $this->conditions[] = sprintf(
            '%s %s %s',
            $this->quoter->quote($leftRef->toString()),
            $operator,
            $this->quoter->quote($rightRef->toString()),
        );

        return $this;
    }

    /**
     * Add an ON condition comparing a column to a bound value.
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $ref = QualifiedRef::parse($column);
        $binding = $this->bindingCounter->next();

        $this->conditions[] = sprintf(
            '%s %s :%s',
            $this->quoter->quote($ref->toString()),
            $operator,
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
