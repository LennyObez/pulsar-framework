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
        $connection->execute('DROP TABLE IF EXISTS cms_collaboration_sessions');
        $connection->execute('DROP TABLE IF EXISTS cms_collaboration_states');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_collaboration_states (
                content_id VARCHAR(36) NOT NULL PRIMARY KEY,
                state_vector TEXT NOT NULL DEFAULT '',
                version INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_collaboration_sessions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                content_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                user_name VARCHAR(255) NOT NULL,
                cursor_position TEXT,
                selection_range TEXT,
                connected_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_collab_sessions_content
                ON cms_collaboration_sessions (content_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_collab_sessions_last_seen
                ON cms_collaboration_sessions (last_seen_at)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_collaboration_states (
                content_id VARCHAR(36) NOT NULL PRIMARY KEY,
                state_vector LONGTEXT NOT NULL,
                version INT NOT NULL DEFAULT 1,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_collaboration_sessions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                content_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                user_name VARCHAR(255) NOT NULL,
                cursor_position TEXT,
                selection_range TEXT,
                connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_collab_sessions_content (content_id),
                INDEX idx_collab_sessions_last_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_collaboration_states (
                content_id VARCHAR(36) NOT NULL PRIMARY KEY,
                state_vector TEXT NOT NULL DEFAULT '',
                version INT NOT NULL DEFAULT 1,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_collaboration_sessions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                content_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                user_name VARCHAR(255) NOT NULL,
                cursor_position TEXT,
                selection_range TEXT,
                connected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_collab_sessions_content
                ON cms_collaboration_sessions (content_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_collab_sessions_last_seen
                ON cms_collaboration_sessions (last_seen_at)
            SQL);
    }
};
