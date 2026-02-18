<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;

use function array_map;
use function explode;
use function in_array;
use function is_a;
use function is_numeric;
use function trim;

/**
 * Parses raw filter query parameters into validated AST nodes.
 *
 * Validates field names against the FilterRegistry, operators against the
 * enumerated set, and values against the declared type. Unknown fields
 * or operators produce 400 Bad Request.
 *
 * Expected query format: `?filter[field]=operator:value`
 * Examples:
 *   - `?filter[status]=eq:active`
 *   - `?filter[age]=gte:18`
 *   - `?filter[role]=in:admin,editor`
 */
#[Api(since: '1.0.0')]
final readonly class FilterParser
{
    public function __construct(
        private FilterRegistry $registry,
    ) {}

    /**
     * Parse filter parameters from a query array.
     *
     * @param string $resourceType The resource type being filtered
     * @param array<string, string> $filterParams Raw filter parameters (field => operator:value)
     * @param list<string> $userRoles Current user's roles for authorization checks
     *
     * @throws ApiException On unknown field, unknown operator, or type mismatch
     *
     * @return list<FilterExpression> Validated filter expressions
     */
    #[NoDiscard]
    public function parse(string $resourceType, array $filterParams, array $userRoles = []): array
    {
        $expressions = [];

        foreach ($filterParams as $field => $rawValue) {
            $definition = $this->registry->get($resourceType, $field);

            // Authorization check
            if ($definition->requiresAuthorization()) {
                $this->assertAuthorized($definition, $userRoles, $field);
            }

            // Parse operator:value
            [$operator, $value] = $this->parseOperatorValue($rawValue, $field);

            // Validate operator is allowed for this field
            if (!$definition->allowsOperator($operator)) {
                throw ApiException::invalidFilterOperator(
                    $operator->value,
                    $field,
                    $resourceType,
                );
            }

            // Cast and validate value
            $typedValue = $this->castValue($value, $definition, $operator, $field);

            $expressions[] = FilterExpression::create($definition->column, $operator, $typedValue);
        }

        return $expressions;
    }

    /**
     * Parse the operator:value string.
     *
     * @return array{0: FilterOperator, 1: string}
     *
     * @throws ApiException If the operator is unknown
     */
    private function parseOperatorValue(string $raw, string $field): array
    {
        $colonPos = strpos($raw, ':');

        if ($colonPos === false) {
            // Default to equality operator
            return [FilterOperator::Equal, $raw];
        }

        $operatorStr = substr($raw, 0, $colonPos);
        $value = substr($raw, $colonPos + 1);

        $operator = FilterOperator::tryFrom($operatorStr);

        if ($operator === null) {
            throw ApiException::unknownFilterOperator($operatorStr, $field);
        }

        return [$operator, $value];
    }

    /**
     * Cast and validate a raw value to the expected type.
     */
    private function castValue(
        string $rawValue,
        FilterDefinition $definition,
        FilterOperator $operator,
        string $field,
    ): mixed {
        // Handle "in" operator — comma-separated list
        if ($operator === FilterOperator::In) {
            $parts = array_map(trim(...), explode(',', $rawValue));

            return array_map(
                fn(string $part): mixed => $this->castSingleValue($part, $definition, $field),
                $parts,
            );
        }

        return $this->castSingleValue($rawValue, $definition, $field);
    }

    /**
     * Cast a single raw value to the expected type.
     *
     * @throws ApiException On type mismatch
     */
    private function castSingleValue(string $rawValue, FilterDefinition $definition, string $field): mixed
    {
        return match ($definition->valueType) {
            FilterValueType::String => $rawValue,
            FilterValueType::Integer => $this->castInteger($rawValue, $field),
            FilterValueType::Float => $this->castFloat($rawValue, $field),
            FilterValueType::Boolean => $this->castBoolean($rawValue, $field),
            FilterValueType::Date, FilterValueType::DateTime => $this->castDate($rawValue, $field),
            FilterValueType::Enum => $this->castEnum($rawValue, $definition, $field),
        };
    }

    private function castInteger(string $value, string $field): int
    {
        if (!is_numeric($value) || (string) (int) $value !== $value) {
            throw ApiException::invalidFilterValue($value, 'integer', $field);
        }

        return (int) $value;
    }

    private function castFloat(string $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw ApiException::invalidFilterValue($value, 'float', $field);
        }

        return (float) $value;
    }

    private function castBoolean(string $value, string $field): bool
    {
        return match ($value) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => throw ApiException::invalidFilterValue($value, 'boolean', $field),
        };
    }

    private function castDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date === false) {
            $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        }

        if ($date === false) {
            throw ApiException::invalidFilterValue($value, 'date', $field);
        }

        return $date;
    }

    /**
     * @throws ApiException
     */
    private function castEnum(string $value, FilterDefinition $definition, string $field): BackedEnum
    {
        $enumClass = $definition->enumClass;

        if ($enumClass === null || !is_a($enumClass, BackedEnum::class, true)) {
            throw ApiException::invalidFilterValue($value, 'enum', $field);
        }

        $enum = $enumClass::tryFrom($value);

        if ($enum === null) {
            throw ApiException::invalidFilterValue($value, 'enum(' . $enumClass . ')', $field);
        }

        return $enum;
    }

    /**
     * @param list<string> $userRoles
     *
     * @throws ApiException If the user lacks the required role
     */
    private function assertAuthorized(FilterDefinition $definition, array $userRoles, string $field): void
    {
        if ($definition->guard !== null && !in_array($definition->guard, $userRoles, true)) {
            throw ApiException::unauthorizedFilter($field, $definition->guard);
        }
    }
}
