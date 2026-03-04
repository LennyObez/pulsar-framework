<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use Pulsar\Api\Api;

use function is_string;

/**
 * A single filter condition in a visual filter definition.
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
        $rawField = $data['field'] ?? '';
        $rawOp = $data['operator'] ?? 'eq';

        return new self(
            field: is_string($rawField) ? $rawField : '',
            operator: FilterOperator::from(is_string($rawOp) ? $rawOp : 'eq'),
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
