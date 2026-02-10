<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function implode;
use function sprintf;

/**
 * Compiles typed filter expressions into SQL fragments with bindings.
 */
#[Internal]
final readonly class ExpressionCompiler
{
    public function __construct(
        private IdentifierQuoter $quoter,
        private BindingCounter $bindings,
    ) {}

    /**
     * Compile a simple comparison (=, !=, <, >, <=, >=).
     */
    public function compare(string $column, string $operator, mixed $value): Expression
    {
        $binding = $this->bindings->next();

        return new Expression(
            sprintf('%s %s :%s', $this->quoter->quote($column), $operator, $binding),
            [$binding => $value],
        );
    }

    /**
     * Compile an IS NULL / IS NOT NULL check.
     */
    public function isNull(string $column, bool $not = false): Expression
    {
        $op = $not ? 'IS NOT NULL' : 'IS NULL';

        return new Expression(
            sprintf('%s %s', $this->quoter->quote($column), $op),
        );
    }

    /**
     * Compile an IN (...) clause.
     *
     * @param list<mixed> $values
     */
    public function in(string $column, array $values, bool $not = false): Expression
    {
        $placeholders = [];
        $bindings = [];
        foreach ($values as $val) {
            $name = $this->bindings->next();
            $placeholders[] = ':' . $name;
            $bindings[$name] = $val;
        }

        $op = $not ? 'NOT IN' : 'IN';

        return new Expression(
            sprintf('%s %s (%s)', $this->quoter->quote($column), $op, implode(', ', $placeholders)),
            $bindings,
        );
    }

    /**
     * Compile a BETWEEN clause.
     */
    public function between(string $column, mixed $low, mixed $high): Expression
    {
        $lowBinding = $this->bindings->next();
        $highBinding = $this->bindings->next();

        return new Expression(
            sprintf('%s BETWEEN :%s AND :%s', $this->quoter->quote($column), $lowBinding, $highBinding),
            [$lowBinding => $low, $highBinding => $high],
        );
    }

    /**
     * Compile a LIKE clause.
     */
    public function like(string $column, LikePattern $pattern, bool $not = false): Expression
    {
        $binding = $this->bindings->next();
        $op = $not ? 'NOT LIKE' : 'LIKE';

        return new Expression(
            sprintf('%s %s :%s', $this->quoter->quote($column), $op, $binding),
            [$binding => $pattern->toString()],
        );
    }

    /**
     * Compile a raw expression.
     */
    public function raw(RawExpression $expr): Expression
    {
        return new Expression($expr->sql, $expr->bindings);
    }

    /**
     * Combine expressions with AND.
     *
     * @param list<Expression> $expressions
     */
    public function and(array $expressions): Expression
    {
        $sqls = [];
        $bindings = [];
        foreach ($expressions as $expr) {
            $sqls[] = $expr->sql;
            foreach ($expr->bindings as $param => $value) {
                $bindings[$param] = $value;
            }
        }

        return new Expression(
            '(' . implode(' AND ', $sqls) . ')',
            $bindings,
        );
    }

    /**
     * Combine expressions with OR.
     *
     * @param list<Expression> $expressions
     */
    public function or(array $expressions): Expression
    {
        $sqls = [];
        $bindings = [];
        foreach ($expressions as $expr) {
            $sqls[] = $expr->sql;
            foreach ($expr->bindings as $param => $value) {
                $bindings[$param] = $value;
            }
        }

        return new Expression(
            '(' . implode(' OR ', $sqls) . ')',
            $bindings,
        );
    }

    public function exists(string $subquerySql, array $bindings = []): Expression
    {
        return new Expression(
            sprintf('EXISTS (%s)', $subquerySql),
            $bindings,
        );
    }
}
