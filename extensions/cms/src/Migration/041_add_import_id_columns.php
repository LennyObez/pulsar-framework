<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

/**
 * Add import_id column to all importable tables.
 *
 * Enables idempotent imports: items with a stable import_id are updated
 * on re-import instead of duplicated. The column is nullable so that
 * user-created records (without import_id) are never affected.
 */
return new class implements MigrationInterface {
    /** @var list<array{table: string, driver_specific: bool}> */
    private const array TABLES = [
        ['table' => 'cms_contents', 'driver_specific' => false],
        ['table' => 'cms_taxonomies', 'driver_specific' => false],
        ['table' => 'cms_taxonomy_terms', 'driver_specific' => false],
        ['table' => 'cms_menus', 'driver_specific' => false],
        ['table' => 'cms_menu_items', 'driver_specific' => false],
        ['table' => 'cms_media_assets', 'driver_specific' => false],
    ];

    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        foreach (self::TABLES as $spec) {
            $table = $spec['table'];

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

        foreach (self::TABLES as $spec) {
            $table = $spec['table'];

            $indexes->ensureAbsent($table, "idx_{$table}_import_id");

            match ($driver) {
                Driver::SQLite => null, // SQLite doesn't support DROP COLUMN before 3.35
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
