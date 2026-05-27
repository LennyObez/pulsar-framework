<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
        return new self(
            field: Coerce::string($data['field'] ?? null),
            operator: FilterOperator::from(Coerce::string($data['operator'] ?? null, 'eq')),
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
