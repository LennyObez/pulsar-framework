<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

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
        $indexes = new IndexOperations($connection);

        foreach (self::TABLES as $table) {
            $connection->execute(
                "ALTER TABLE {$table} ADD COLUMN import_id VARCHAR(255) DEFAULT NULL",
            );

            $indexes->ensure($table, "idx_{$table}_import_id", ['import_id'], unique: true);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        foreach (self::TABLES as $table) {
            $indexes->ensureAbsent($table, "idx_{$table}_import_id");

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
