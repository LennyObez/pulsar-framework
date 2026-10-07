<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;

/**
 * Schema management interface for creating and modifying database tables.
 * @api
 */
#[Api(since: '1.0.0')]
interface SchemaBuilderInterface
{
    /**
     * Create a new table.
     */
    public function create(string $table, callable $callback): void;

    /**
     * Modify an existing table.
     */
    public function table(string $table, callable $callback): void;

    /**
     * Drop a table.
     */
    public function drop(string $table): void;

    /**
     * Drop a table if it exists.
     */
    public function dropIfExists(string $table): void;

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void;

    /**
     * Check if a table exists.
     */
    public function hasTable(string $table): bool;

    /**
     * Check if a column exists on a table.
     */
    public function hasColumn(string $table, string $column): bool;
}
