<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_installed_plugins (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                slug VARCHAR(200) NOT NULL,
                display_name VARCHAR(500) NOT NULL,
                version VARCHAR(50) NOT NULL,
                description TEXT DEFAULT NULL,
                author_name VARCHAR(200) DEFAULT NULL,
                author_url VARCHAR(500) DEFAULT NULL,
                license VARCHAR(100) DEFAULT NULL,
                manifest_hash VARCHAR(128) NOT NULL,
                package_hash VARCHAR(128) NOT NULL,
                provenance_verified BOOLEAN NOT NULL DEFAULT false,
                signature_verified BOOLEAN NOT NULL DEFAULT false,
                capabilities JSONB NOT NULL DEFAULT '[]',
                boot_order INTEGER NOT NULL DEFAULT 0,
                is_enabled BOOLEAN NOT NULL DEFAULT false,
                storage_path VARCHAR(500) NOT NULL,
                installed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                installed_by VARCHAR(36) NOT NULL,
                enabled_at TIMESTAMPTZ DEFAULT NULL,
                enabled_by VARCHAR(36) DEFAULT NULL,
                disabled_at TIMESTAMPTZ DEFAULT NULL,
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL, $driver));

        // Expression index: PostgreSQL + SQLite support it; MySQL fallback
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX uq_plugin_slug_tenant
                    ON cms_installed_plugins (slug, tenant_id)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_plugin_slug_tenant
                    ON cms_installed_plugins (
                        slug,
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    )
                SQL);
        }

        // An engine without partial indexes cannot filter on `is_enabled` and `deleted_at`,
        // so it has to carry them in the key instead.
        $indexes->ensure(
            'cms_installed_plugins',
            'idx_plugin_enabled',
            $connection->dialect()->supportsPartialIndexes()
                ? ['boot_order']
                : ['boot_order', 'is_enabled', 'deleted_at'],
            where: 'is_enabled = true AND deleted_at IS NULL',
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_installed_plugins');
    }
};
