<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * AST node representing a single filter condition.
 *
 * Each expression maps a registered field name, an enumerated operator,
 * and a typed value. This is the output of parsing: never raw input.
 */
#[Api(since: '1.0.0')]
final readonly class FilterExpression
{
    /**
     * @param string $field The registered filter field name
     * @param FilterOperator $operator The enumerated operator
     * @param mixed $value The validated, typed value
     */
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value,
    ) {}

    /**
     * Create from parsed, validated inputs.
     */
    #[NoDiscard]
    public static function create(string $field, FilterOperator $operator, mixed $value): self
    {
        return new self(
            field: $field,
            operator: $operator,
            value: $value,
        );
    }
}
