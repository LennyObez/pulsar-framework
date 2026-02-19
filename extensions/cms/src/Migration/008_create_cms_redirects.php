<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE cms_redirects (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                from_path VARCHAR(2000) NOT NULL,
                to_path VARCHAR(2000) NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                locale VARCHAR(5) DEFAULT NULL,
                hits BIGINT NOT NULL DEFAULT 0,
                last_hit_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_by VARCHAR(36) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_redirect_status_code CHECK (status_code IN (301, 308))
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX uq_redirect_from_locale_tenant
                ON cms_redirects (
                    from_path,
                    COALESCE(locale, ''),
                    COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                )
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_redirects');
    }
};
