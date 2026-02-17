<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add tenant_id column to forum_tags for multi-tenancy support.
 *
 * Without tenant scoping, tags are shared across all tenants. This migration
 * adds a nullable tenant_id column with an index to enable per-tenant tag
 * isolation. Existing rows retain NULL (shared/global tags).
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(
            'ALTER TABLE forum_tags ADD COLUMN tenant_id VARCHAR(36) DEFAULT NULL',
        );

        match ($driver) {
            Driver::MySQL => $connection->execute(
                'CREATE INDEX idx_forum_tags_tenant ON forum_tags (tenant_id)',
            ),
            Driver::SQLite, Driver::PostgreSQL => $connection->execute(
                'CREATE INDEX IF NOT EXISTS idx_forum_tags_tenant ON forum_tags (tenant_id)',
            ),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $connection->execute(
                'DROP INDEX IF EXISTS idx_forum_tags_tenant',
            ),
            Driver::MySQL => $connection->execute(
                'ALTER TABLE forum_tags DROP INDEX idx_forum_tags_tenant',
            ),
            Driver::PostgreSQL => $connection->execute(
                'DROP INDEX IF EXISTS idx_forum_tags_tenant',
            ),
        };

        match ($driver) {
            Driver::SQLite => null,
            Driver::MySQL => $connection->execute(
                'ALTER TABLE forum_tags DROP COLUMN tenant_id',
            ),
            Driver::PostgreSQL => $connection->execute(
                'ALTER TABLE forum_tags DROP COLUMN IF EXISTS tenant_id',
            ),
        };
    }
};
