<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

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
        $indexes = new IndexOperations($connection);

        $connection->execute(
            'ALTER TABLE forum_tags ADD COLUMN tenant_id VARCHAR(36) DEFAULT NULL',
        );

        $indexes->ensure('forum_tags', 'idx_forum_tags_tenant', ['tenant_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $indexes->ensureAbsent('forum_tags', 'idx_forum_tags_tenant');

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
