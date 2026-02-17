<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_merge;
use function count;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;

/**
 * Compiles visual filter definitions into SQL WHERE clauses.
 *
 * Takes a FilterGroup (from the visual filter builder UI) and
 * produces parameterized SQL with named bindings.
 */
#[Api(since: '1.0.0')]
final readonly class FilterCompiler
{
    /**
     * Maximum nesting depth for filter groups.
     */
    private const int MAX_DEPTH = 5;

    /**
     * Maximum total conditions across all groups.
     */
    private const int MAX_CONDITIONS = 50;

    /**
     * Allowed column name pattern to prevent SQL injection.
     */
    private const string COLUMN_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Compile a filter group into a SQL WHERE clause and parameters.
     *
     * @return array{sql: string, params: array<string, mixed>}
     *
     * @throws InvalidArgumentException If filter exceeds limits or has invalid columns
     */
    public function compile(FilterGroup $group): array
    {
        if ($group->isEmpty()) {
            return ['sql' => '1=1', 'params' => []];
        }

        if ($group->totalConditions() > self::MAX_CONDITIONS) {
            throw new InvalidArgumentException(
                'Filter exceeds maximum of ' . self::MAX_CONDITIONS . ' conditions',
            );
        }

        $paramIndex = 0;

        return $this->compileGroup($group, $paramIndex, depth: 0);
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function compileGroup(FilterGroup $group, int &$paramIndex, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'Filter group nesting exceeds maximum depth of ' . self::MAX_DEPTH,
            );
        }

        $parts = [];
        $params = [];

        foreach ($group->conditions as $condition) {
            $result = $this->compileCondition($condition, $paramIndex);
            $parts[] = $result['sql'];
            $params = array_merge($params, $result['params']);
        }

        foreach ($group->groups as $subGroup) {
            if (!$subGroup->isEmpty()) {
                $result = $this->compileGroup($subGroup, $paramIndex, $depth + 1);
                $parts[] = '(' . $result['sql'] . ')';
                $params = array_merge($params, $result['params']);
            }
        }

        if ($parts === []) {
            return ['sql' => '1=1', 'params' => []];
        }

        $joiner = $group->logic === FilterLogic::And ? ' AND ' : ' OR ';
        $sql = implode($joiner, $parts);

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function compileCondition(FilterCondition $condition, int &$paramIndex): array
    {
        $field = $condition->field;

        if (!preg_match(self::COLUMN_PATTERN, $field)) {
            throw new InvalidArgumentException("Invalid column name: '{$field}'");
        }

        $operator = $condition->operator;

        if (!$operator->requiresValue()) {
            return [
                'sql' => "{$field} {$operator->toSql()}",
                'params' => [],
            ];
        }

        $paramName = 'f_' . $paramIndex++;

        $valueStr = is_string($condition->value) ? $condition->value : (is_scalar($condition->value) ? (string) $condition->value : '');

        return match ($operator) {
            FilterOperator::Contains => [
                'sql' => "{$field} LIKE :{$paramName}",
                'params' => [$paramName => '%' . $valueStr . '%'],
            ],
            FilterOperator::NotContains => [
                'sql' => "{$field} NOT LIKE :{$paramName}",
                'params' => [$paramName => '%' . $valueStr . '%'],
            ],
            FilterOperator::StartsWith => [
                'sql' => "{$field} LIKE :{$paramName}",
                'params' => [$paramName => $valueStr . '%'],
            ],
            FilterOperator::EndsWith => [
                'sql' => "{$field} LIKE :{$paramName}",
                'params' => [$paramName => '%' . $valueStr],
            ],
            FilterOperator::In, FilterOperator::NotIn => $this->compileInCondition(
                $field,
                $operator,
                $condition->value,
                $paramIndex,
            ),
            FilterOperator::Between => $this->compileBetweenCondition(
                $field,
                $condition->value,
                $paramIndex,
            ),
            default => [
                'sql' => "{$field} {$operator->toSql()} :{$paramName}",
                'params' => [$paramName => $condition->value],
            ],
        };
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function compileInCondition(
        string $field,
        FilterOperator $operator,
        mixed $value,
        int &$paramIndex,
    ): array {
        $values = is_array($value) ? $value : [$value];
        $placeholders = [];
        $params = [];

        foreach ($values as $v) {
            $p = 'f_' . $paramIndex++;
            $placeholders[] = ':' . $p;
            $params[$p] = $v;
        }

        $inList = implode(', ', $placeholders);
        $sqlOp = $operator === FilterOperator::NotIn ? 'NOT IN' : 'IN';

        return [
            'sql' => "{$field} {$sqlOp} ({$inList})",
            'params' => $params,
        ];
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function compileBetweenCondition(string $field, mixed $value, int &$paramIndex): array
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException("BETWEEN operator requires exactly 2 values for field '{$field}'");
        }

        $p1 = 'f_' . $paramIndex++;
        $p2 = 'f_' . $paramIndex++;

        return [
            'sql' => "{$field} BETWEEN :{$p1} AND :{$p2}",
            'params' => [$p1 => $value[0], $p2 => $value[1]],
        ];
    }
}
