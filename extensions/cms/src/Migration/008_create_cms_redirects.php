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
            CREATE TABLE IF NOT EXISTS cms_redirects (
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
            SQL, $driver));

        // Expression index with COALESCE: PostgreSQL + SQLite support it;
        // MySQL fallback uses a standard composite index
        if ($driver === Driver::MySQL) {
            $indexes->ensure(
                'cms_redirects',
                'uq_redirect_from_locale_tenant',
                ['from_path', 'locale', 'tenant_id'],
                unique: true,
            );
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_redirect_from_locale_tenant
                    ON cms_redirects (
                        from_path,
                        COALESCE(locale, ''),
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000')
                    )
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_redirects');
    }
};
