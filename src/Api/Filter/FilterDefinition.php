<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use Pulsar\Api\Api;

use function in_array;

/**
 * Defines a single allowed filter on a resource.
 *
 * Each definition specifies the target column/expression, allowed operators,
 * value type, and optional authorization guard.
 */
#[Api(since: '1.0.0')]
final readonly class FilterDefinition
{
    /**
     * @param string $column The safe query builder column/expression name
     * @param FilterValueType $valueType Expected type for the filter value
     * @param list<FilterOperator> $allowedOperators Operators permitted for this field
     * @param string|null $guard Role or permission required to use this filter
     * @param class-string|null $enumClass Enum class for Enum-type filters
     */
    public function __construct(
        public string $column,
        public FilterValueType $valueType,
        public array $allowedOperators,
        public ?string $guard = null,
        public ?string $enumClass = null,
    ) {}

    /**
     * Check if the given operator is allowed for this filter.
     */
    public function allowsOperator(FilterOperator $operator): bool
    {
        return in_array($operator, $this->allowedOperators, true);
    }

    /**
     * Check if this filter requires authorization.
     */
    public function requiresAuthorization(): bool
    {
        return $this->guard !== null;
    }
}
