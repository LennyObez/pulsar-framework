<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS user_devices');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_devices (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                device_name VARCHAR(100) NOT NULL,
                platform VARCHAR(10) NOT NULL,
                app_version VARCHAR(50) NOT NULL,
                api_token_hash VARCHAR(128) NOT NULL,
                last_seen_at TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_user_devices_user_id
                ON user_devices (user_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_user_devices_token_hash
                ON user_devices (api_token_hash)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_devices (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                device_name VARCHAR(100) NOT NULL,
                platform VARCHAR(10) NOT NULL,
                app_version VARCHAR(50) NOT NULL,
                api_token_hash VARCHAR(128) NOT NULL,
                last_seen_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_user_devices_user_id (user_id),
                UNIQUE INDEX idx_user_devices_token_hash (api_token_hash),
                CONSTRAINT fk_user_devices_user_id
                    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_devices (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                device_name VARCHAR(100) NOT NULL,
                platform VARCHAR(10) NOT NULL,
                app_version VARCHAR(50) NOT NULL,
                api_token_hash VARCHAR(128) NOT NULL,
                last_seen_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_user_devices_user_id
                    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_user_devices_user_id
                ON user_devices (user_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_user_devices_token_hash
                ON user_devices (api_token_hash)
            SQL);
    }
};
