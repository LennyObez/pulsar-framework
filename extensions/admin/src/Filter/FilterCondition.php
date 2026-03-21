<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use Pulsar\Api\Api;

use function is_string;

/**
 * A single filter condition in a visual filter definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FilterCondition
{
    /**
     * @param string $field Database column or field key
     * @param FilterOperator $operator Comparison operator
     * @param mixed $value Comparison value(s)
     */
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $field = isset($data['field']) && is_string($data['field']) ? $data['field'] : '';
        $operator = isset($data['operator']) && is_string($data['operator']) ? $data['operator'] : 'eq';

        return new self(
            field: $field,
            operator: FilterOperator::from($operator),
            value: $data['value'] ?? null,
        );
    }

    /**
     * @return array{field: string, operator: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }
}
