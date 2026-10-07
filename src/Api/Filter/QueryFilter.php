<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use Pulsar\Api\Api;

use function is_string;

/**
 * Applies validated filter expressions to a query builder abstraction.
 *
 * This class bridges the gap between the parsed filter AST and the
 * database query builder. It maps each filter expression to a safe
 * query builder call: no raw SQL is ever constructed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueryFilter
{
    /**
     * Apply filter expressions to a query builder.
     *
     * The query builder must implement a compatible interface with
     * where/whereIn methods. Returns the modified conditions as
     * an array of column/operator/value tuples for the query builder.
     *
     * @param list<FilterExpression> $expressions Validated filter expressions
     *
     * @return list<array{column: string, operator: string, value: mixed}> Safe query conditions
     */
    public function apply(array $expressions): array
    {
        $conditions = [];

        foreach ($expressions as $expression) {
            $conditions[] = match ($expression->operator) {
                FilterOperator::Equal => [
                    'column' => $expression->field,
                    'operator' => '=',
                    'value' => $expression->value,
                ],
                FilterOperator::NotEqual => [
                    'column' => $expression->field,
                    'operator' => '!=',
                    'value' => $expression->value,
                ],
                FilterOperator::GreaterThan => [
                    'column' => $expression->field,
                    'operator' => '>',
                    'value' => $expression->value,
                ],
                FilterOperator::GreaterThanOrEqual => [
                    'column' => $expression->field,
                    'operator' => '>=',
                    'value' => $expression->value,
                ],
                FilterOperator::LessThan => [
                    'column' => $expression->field,
                    'operator' => '<',
                    'value' => $expression->value,
                ],
                FilterOperator::LessThanOrEqual => [
                    'column' => $expression->field,
                    'operator' => '<=',
                    'value' => $expression->value,
                ],
                FilterOperator::In => [
                    'column' => $expression->field,
                    'operator' => 'IN',
                    'value' => $expression->value,
                ],
                FilterOperator::Contains => [
                    'column' => $expression->field,
                    'operator' => 'LIKE',
                    'value' => '%' . $this->escapeLike(is_string($expression->value) ? $expression->value : '') . '%',
                ],
                FilterOperator::StartsWith => [
                    'column' => $expression->field,
                    'operator' => 'LIKE',
                    'value' => $this->escapeLike(is_string($expression->value) ? $expression->value : '') . '%',
                ],
            };
        }

        return $conditions;
    }

    /**
     * Escape LIKE wildcards to prevent injection through wildcard characters.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
