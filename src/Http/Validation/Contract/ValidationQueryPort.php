<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Contract;

use Pulsar\Api\Api;

/**
 * Port for database queries required by validation rules.
 *
 * Implementations must use parameterized queries. The value objects
 * (TableName, ColumnName) guarantee identifier safety at construction.
 */
#[Api(since: '1.0.0')]
interface ValidationQueryPort
{
    /**
     * Check whether a value exists in the given table/column.
     */
    public function exists(
        TableName $table,
        ColumnName $column,
        mixed $value,
        WhereConditions $where = new WhereConditions(),
    ): bool;

    /**
     * Check whether a value is unique in the given table/column.
     */
    public function isUnique(
        TableName $table,
        ColumnName $column,
        mixed $value,
        WhereConditions $where = new WhereConditions(),
    ): bool;
}
