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
            CREATE TABLE IF NOT EXISTS cms_site_settings (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                "group" VARCHAR(100) NOT NULL,
                key VARCHAR(200) NOT NULL,
                locale VARCHAR(5) DEFAULT NULL,
                value TEXT NOT NULL,
                value_type VARCHAR(20) NOT NULL DEFAULT 'string',
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_by VARCHAR(36) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_setting_value_type CHECK (value_type IN ('string', 'int', 'bool', 'float', 'json', 'encrypted'))
            )
            SQL, $driver));

        // Expression index with COALESCE: PostgreSQL + SQLite support it;
        // MySQL fallback uses a standard composite index
        if ($driver === Driver::MySQL) {
            $indexes->ensure(
                'cms_site_settings',
                'uq_setting_tenant_group_key_locale',
                ['tenant_id', 'group', 'key', 'locale'],
                unique: true,
            );
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uq_setting_tenant_group_key_locale
                    ON cms_site_settings (
                        COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'),
                        "group",
                        key,
                        COALESCE(locale, '')
                    )
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_site_settings');
    }
};
