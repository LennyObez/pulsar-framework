<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Builder for filter definitions.
 *
 * Provides static factory methods for creating type-safe filter definitions
 * with fluent guard configuration.
 *
 * Example:
 *     $filters->register('users', [
 *         'status' => Filter::enum(UserStatus::class),
 *         'created_after' => Filter::date('created_at', '>='),
 *         'internal_status' => Filter::enum(InternalStatus::class)->guard('admin'),
 *     ]);
 */
#[Api(since: '1.0.0')]
final class Filter
{
    private ?string $guardRole = null;

    /**
     * @param string $column Target column for query builder
     * @param FilterValueType $valueType Expected value type
     * @param list<FilterOperator> $allowedOperators Permitted operators
     * @param class-string|null $enumClass For enum-type filters
     */
    private function __construct(
        private readonly string $column,
        private readonly FilterValueType $valueType,
        private readonly array $allowedOperators,
        private readonly ?string $enumClass = null,
    ) {}

    /**
     * Create a string filter with common text operators.
     */
    #[NoDiscard]
    public static function string(string $column = ''): self
    {
        return new self(
            column: $column,
            valueType: FilterValueType::String,
            allowedOperators: [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::Contains,
                FilterOperator::StartsWith,
                FilterOperator::In,
            ],
        );
    }

    /**
     * Create an integer filter with numeric operators.
     */
    #[NoDiscard]
    public static function integer(string $column = ''): self
    {
        return new self(
            column: $column,
            valueType: FilterValueType::Integer,
            allowedOperators: [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::GreaterThan,
                FilterOperator::GreaterThanOrEqual,
                FilterOperator::LessThan,
                FilterOperator::LessThanOrEqual,
                FilterOperator::In,
            ],
        );
    }

    /**
     * Create a float filter with numeric operators.
     */
    #[NoDiscard]
    public static function float(string $column = ''): self
    {
        return new self(
            column: $column,
            valueType: FilterValueType::Float,
            allowedOperators: [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::GreaterThan,
                FilterOperator::GreaterThanOrEqual,
                FilterOperator::LessThan,
                FilterOperator::LessThanOrEqual,
            ],
        );
    }

    /**
     * Create a boolean filter (equality only).
     */
    #[NoDiscard]
    public static function boolean(string $column = ''): self
    {
        return new self(
            column: $column,
            valueType: FilterValueType::Boolean,
            allowedOperators: [FilterOperator::Equal],
        );
    }

    /**
     * Create a date filter with range operators.
     *
     * @param string $column The target column name
     * @param string $defaultOperatorHint Hint for default operator ('>=', '<=', '=')
     */
    #[NoDiscard]
    public static function date(string $column, string $defaultOperatorHint = '='): self
    {
        return new self(
            column: $column,
            valueType: FilterValueType::Date,
            allowedOperators: [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::GreaterThan,
                FilterOperator::GreaterThanOrEqual,
                FilterOperator::LessThan,
                FilterOperator::LessThanOrEqual,
            ],
        );
    }

    /**
     * Create an enum filter (equality and in-set operators).
     *
     * @param class-string $enumClass The backed enum class
     */
    #[NoDiscard]
    public static function enum(string $enumClass, string $column = ''): self
    {
        return new self(
            column: $column,
            valueType: FilterValueType::Enum,
            allowedOperators: [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::In,
            ],
            enumClass: $enumClass,
        );
    }

    /**
     * Add an authorization guard to this filter.
     *
     * Only users with the specified role/permission can use this filter.
     */
    public function guard(string $role): self
    {
        return clone($this, ['guardRole' => $role]);
    }

    /**
     * Build the filter definition.
     *
     * @param string $fieldName The API field name (used when column is empty)
     */
    #[NoDiscard]
    public function build(string $fieldName): FilterDefinition
    {
        return new FilterDefinition(
            column: $this->column !== '' ? $this->column : $fieldName,
            valueType: $this->valueType,
            allowedOperators: $this->allowedOperators,
            guard: $this->guardRole,
            enumClass: $this->enumClass,
        );
    }
}
