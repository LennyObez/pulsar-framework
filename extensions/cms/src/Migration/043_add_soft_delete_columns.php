<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

/**
 * Add soft-delete (deleted_at) columns to cms_api_keys and cms_site_settings.
 *
 * These tables lacked soft-delete support, which is required for audit trails
 * in regulated environments. Deleted records are retained with a non-null
 * deleted_at timestamp and filtered out by default queries.
 */
return new class implements MigrationInterface {
    /** @var list<array{table: string, index: string}> */
    private const array TABLES = [
        ['table' => 'cms_api_keys', 'index' => 'idx_api_keys_deleted'],
        ['table' => 'cms_site_settings', 'index' => 'idx_site_settings_deleted'],
    ];

    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $columnType = CmsDdl::adapt('TIMESTAMPTZ', $driver);

        foreach (self::TABLES as $spec) {
            $connection->execute(
                "ALTER TABLE {$spec['table']} ADD COLUMN deleted_at {$columnType} DEFAULT NULL",
            );

            $indexes->ensure($spec['table'], $spec['index'], ['deleted_at']);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        foreach (self::TABLES as $spec) {
            $indexes->ensureAbsent($spec['table'], $spec['index']);

            match ($driver) {
                // SQLite does not support DROP COLUMN before 3.35; skip on down
                Driver::SQLite => null,
                Driver::MySQL => $connection->execute(
                    "ALTER TABLE {$spec['table']} DROP COLUMN deleted_at",
                ),
                Driver::PostgreSQL => $connection->execute(
                    "ALTER TABLE {$spec['table']} DROP COLUMN IF EXISTS deleted_at",
                ),
            };
        }
    }
};
