<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_installed_themes (
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
                is_active BOOLEAN NOT NULL DEFAULT false,
                storage_path VARCHAR(500) NOT NULL,
                installed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                installed_by VARCHAR(36) NOT NULL,
                activated_at TIMESTAMPTZ DEFAULT NULL,
                activated_by VARCHAR(36) DEFAULT NULL,
                deactivated_at TIMESTAMPTZ DEFAULT NULL,
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL, $driver));

        // Expression index: PostgreSQL + SQLite support it; MySQL fallback
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX uq_theme_slug_tenant
                    ON cms_installed_themes (slug, tenant_id)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_theme_slug_tenant
                    ON cms_installed_themes (
                        slug,
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    )
                SQL);
        }

        // Expression + partial index: PostgreSQL + SQLite support it; MySQL fallback
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX uq_theme_active_tenant
                    ON cms_installed_themes (tenant_id, is_active, deleted_at)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_theme_active_tenant
                    ON cms_installed_themes (
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    )
                    WHERE is_active = true AND deleted_at IS NULL
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_installed_themes');
    }
};
