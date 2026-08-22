<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add version columns to cms_orders, cms_customers, and cms_products.
 *
 * Enables optimistic locking: each UPDATE increments the version column and
 * includes a WHERE version = :expected clause. Concurrent conflicting writes
 * are detected and rejected without database-level locks.
 */
return new class implements MigrationInterface {
    /** @var list<string> */
    private const array TABLES = [
        'cms_orders',
        'cms_customers',
        'cms_products',
    ];

    public function up(ConnectionInterface $connection): void
    {
        foreach (self::TABLES as $table) {
            $connection->execute(
                "ALTER TABLE {$table} ADD COLUMN version INTEGER NOT NULL DEFAULT 1",
            );
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        foreach (self::TABLES as $table) {
            match ($driver) {
                Driver::SQLite => null,
                Driver::MySQL => $connection->execute(
                    "ALTER TABLE {$table} DROP COLUMN version",
                ),
                Driver::PostgreSQL => $connection->execute(
                    "ALTER TABLE {$table} DROP COLUMN IF EXISTS version",
                ),
            };
        }
    }
};
