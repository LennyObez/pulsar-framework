<?php

declare(strict_types=1);

namespace Pulsar\Api\Sort;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * AST node representing a single sort condition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SortExpression
{
    /**
     * @param string $column The safe query builder column name
     * @param SortDirection $direction The sort direction
     */
    public function __construct(
        public string $column,
        public SortDirection $direction,
    ) {}

    #[NoDiscard]
    public static function create(string $column, SortDirection $direction): self
    {
        return new self(column: $column, direction: $direction);
    }
}
