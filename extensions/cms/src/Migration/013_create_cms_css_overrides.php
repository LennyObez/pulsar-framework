<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_css_overrides (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                tenant_key VARCHAR(36) NOT NULL GENERATED ALWAYS AS (
                    COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                ) STORED,
                theme_id VARCHAR(36) NOT NULL,
                version INTEGER NOT NULL,
                css_content TEXT NOT NULL,
                css_hash VARCHAR(128) NOT NULL,
                token_overrides JSONB DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_by VARCHAR(36) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_css_override_theme FOREIGN KEY (theme_id) REFERENCES cms_installed_themes (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_css_overrides',
            'uq_css_override_tenant_theme_version',
            ['tenant_key', 'theme_id', 'version'],
            unique: true,
        );

        $indexes->ensure(
            'cms_css_overrides',
            'idx_css_override_tenant_theme_active',
            ['tenant_id', 'theme_id', 'is_active'],
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_css_overrides');
    }
};
