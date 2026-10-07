<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Contract;

use Pulsar\Api\Api;

/**
 * A single WHERE condition binding a column name to a value.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WhereCondition
{
    public function __construct(
        public ColumnName $column,
        public mixed $value,
    ) {}
}
