<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE cms_css_overrides (
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
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX uq_css_override_tenant_theme_version
                ON cms_css_overrides (tenant_key, theme_id, version)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX idx_css_override_tenant_theme_active
                ON cms_css_overrides (tenant_id, theme_id, is_active)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_css_overrides');
    }
};
