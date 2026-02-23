<?php

declare(strict_types=1);

namespace Pulsar\Database\Introspection;

use Pulsar\Api\Api;

/**
 * Contract for database schema introspection.
 */
#[Api(since: '1.0.0')]
interface DatabaseIntrospectorInterface
{
    /**
     * List all user tables in the database.
     *
     * @return list<TableInfo>
     */
    public function tables(): array;

    /**
     * List all columns for a given table.
     *
     * @return list<ColumnInfo>
     */
    public function columns(string $table): array;

    /**
     * Determine the primary key column for a table, or null if none.
     */
    public function primaryKey(string $table): ?string;
}
