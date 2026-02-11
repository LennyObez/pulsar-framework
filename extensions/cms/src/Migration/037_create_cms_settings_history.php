<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        match ($connection->driver()) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_settings_history');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_settings_history (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36),
                setting_group VARCHAR(100) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                locale VARCHAR(10),
                old_value TEXT,
                new_value TEXT NOT NULL,
                changed_by VARCHAR(36) NOT NULL,
                changed_at TEXT NOT NULL
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_changed_by
                ON cms_settings_history (changed_by)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_group_key
                ON cms_settings_history (setting_group, setting_key)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_changed_at
                ON cms_settings_history (changed_at)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_tenant_group
                ON cms_settings_history (tenant_id, setting_group)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_settings_history (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36),
                setting_group VARCHAR(100) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                locale VARCHAR(10),
                old_value TEXT,
                new_value TEXT NOT NULL,
                changed_by VARCHAR(36) NOT NULL,
                changed_at DATETIME(6) NOT NULL,
                INDEX idx_settings_history_changed_by (changed_by),
                INDEX idx_settings_history_group_key (setting_group, setting_key),
                INDEX idx_settings_history_changed_at (changed_at),
                INDEX idx_settings_history_tenant_group (tenant_id, setting_group)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_settings_history (
                id UUID NOT NULL PRIMARY KEY,
                tenant_id UUID,
                setting_group VARCHAR(100) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                locale VARCHAR(10),
                old_value TEXT,
                new_value TEXT NOT NULL,
                changed_by UUID NOT NULL,
                changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_changed_by
                ON cms_settings_history (changed_by)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_group_key
                ON cms_settings_history (setting_group, setting_key)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_changed_at
                ON cms_settings_history (changed_at)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_settings_history_tenant_group
                ON cms_settings_history (tenant_id, setting_group)
            SQL);
    }
};
