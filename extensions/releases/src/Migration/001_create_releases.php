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
        $connection->execute('DROP TABLE IF EXISTS beta_signups');
        $connection->execute('DROP TABLE IF EXISTS releases');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS releases (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                version VARCHAR(32) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                release_date TEXT NOT NULL,
                release_notes TEXT NOT NULL,
                minimum_os_version VARCHAR(32) NOT NULL,
                download_url VARCHAR(512),
                is_beta INTEGER NOT NULL DEFAULT 0,
                is_stable INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_releases_platform_stable
                ON releases (platform, is_stable)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_releases_platform_date
                ON releases (platform, release_date DESC)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS beta_signups (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                device_type VARCHAR(16) NOT NULL,
                camera_brands TEXT NOT NULL DEFAULT '[]',
                signed_up_at TEXT NOT NULL,
                invited_at TEXT,
                invite_token_hash VARCHAR(128)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_beta_signups_email
                ON beta_signups (email)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS releases (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                version VARCHAR(32) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                release_date TIMESTAMP NOT NULL,
                release_notes TEXT NOT NULL,
                minimum_os_version VARCHAR(32) NOT NULL,
                download_url VARCHAR(512),
                is_beta BOOLEAN NOT NULL DEFAULT FALSE,
                is_stable BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_releases_platform_stable (platform, is_stable),
                INDEX idx_releases_platform_date (platform, release_date DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS beta_signups (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                device_type VARCHAR(16) NOT NULL,
                camera_brands JSON NOT NULL,
                signed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                invited_at TIMESTAMP NULL,
                invite_token_hash VARCHAR(128),
                UNIQUE INDEX idx_beta_signups_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS releases (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                version VARCHAR(32) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                release_date TIMESTAMPTZ NOT NULL,
                release_notes TEXT NOT NULL,
                minimum_os_version VARCHAR(32) NOT NULL,
                download_url VARCHAR(512),
                is_beta BOOLEAN NOT NULL DEFAULT FALSE,
                is_stable BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_releases_platform_stable
                ON releases (platform, is_stable)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_releases_platform_date
                ON releases (platform, release_date DESC)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS beta_signups (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                device_type VARCHAR(16) NOT NULL,
                camera_brands JSONB NOT NULL DEFAULT '[]',
                signed_up_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                invited_at TIMESTAMPTZ,
                invite_token_hash VARCHAR(128)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_beta_signups_email
                ON beta_signups (email)
            SQL);
    }
};
