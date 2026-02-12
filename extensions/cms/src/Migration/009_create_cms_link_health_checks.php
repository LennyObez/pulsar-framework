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
            CREATE TABLE IF NOT EXISTS cms_link_health_checks (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                source_content_id VARCHAR(36) NOT NULL,
                source_locale VARCHAR(5) NOT NULL,
                target_url VARCHAR(2000) NOT NULL,
                is_broken BOOLEAN NOT NULL DEFAULT false,
                is_redirected BOOLEAN NOT NULL DEFAULT false,
                http_status_code INTEGER DEFAULT NULL,
                last_checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_link_health_content FOREIGN KEY (source_content_id) REFERENCES cms_contents (id) ON DELETE CASCADE
            )
            SQL, $driver));

        // Partial index: supported by PostgreSQL and SQLite, fallback for MySQL
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_link_health_broken_tenant
                    ON cms_link_health_checks (is_broken, tenant_id)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_link_health_broken_tenant
                    ON cms_link_health_checks (is_broken, tenant_id)
                    WHERE is_broken = true
                SQL);
        }

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_link_health_content_locale
                ON cms_link_health_checks (source_content_id, source_locale)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_link_health_checks');
    }
};
