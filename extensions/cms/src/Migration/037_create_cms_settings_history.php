<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        match ($connection->driver()) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };

        // Only the column types below differ per engine; the indexes never did, and were
        // written out three times for no reason but the shape of this file.
        $indexes = new IndexOperations($connection);

        $indexes->ensure('cms_settings_history', 'idx_settings_history_changed_by', ['changed_by']);
        $indexes->ensure('cms_settings_history', 'idx_settings_history_group_key', ['setting_group', 'setting_key']);
        $indexes->ensure('cms_settings_history', 'idx_settings_history_changed_at', ['changed_at']);
        $indexes->ensure('cms_settings_history', 'idx_settings_history_tenant_group', ['tenant_id', 'setting_group']);
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
                changed_at DATETIME(6) NOT NULL
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
    }
};
