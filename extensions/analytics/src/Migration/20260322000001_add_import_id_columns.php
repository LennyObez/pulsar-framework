<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add import_id column to analytics tables for idempotent imports.
 */
return new class implements MigrationInterface {
    /** @var list<string> */
    private const array TABLES = [
        'analytics_sites',
        'analytics_goals',
    ];

    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        foreach (self::TABLES as $table) {
            $connection->execute(
                "ALTER TABLE {$table} ADD COLUMN import_id VARCHAR(255) DEFAULT NULL",
            );

            match ($driver) {
                Driver::SQLite => $connection->execute(
                    "CREATE UNIQUE INDEX IF NOT EXISTS idx_{$table}_import_id ON {$table} (import_id)",
                ),
                Driver::MySQL => $connection->execute(
                    "CREATE UNIQUE INDEX idx_{$table}_import_id ON {$table} (import_id)",
                ),
                Driver::PostgreSQL => $connection->execute(
                    "CREATE UNIQUE INDEX IF NOT EXISTS idx_{$table}_import_id ON {$table} (import_id)",
                ),
            };
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        foreach (self::TABLES as $table) {
            match ($driver) {
                Driver::SQLite => $connection->execute(
                    "DROP INDEX IF EXISTS idx_{$table}_import_id",
                ),
                Driver::MySQL => $connection->execute(
                    "ALTER TABLE {$table} DROP INDEX idx_{$table}_import_id",
                ),
                Driver::PostgreSQL => $connection->execute(
                    "DROP INDEX IF EXISTS idx_{$table}_import_id",
                ),
            };

            match ($driver) {
                Driver::SQLite => null,
                Driver::MySQL => $connection->execute(
                    "ALTER TABLE {$table} DROP COLUMN import_id",
                ),
                Driver::PostgreSQL => $connection->execute(
                    "ALTER TABLE {$table} DROP COLUMN IF EXISTS import_id",
                ),
            };
        }
    }
};
