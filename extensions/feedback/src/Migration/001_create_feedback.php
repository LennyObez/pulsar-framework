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
        $connection->execute('DROP TABLE IF EXISTS feedback');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS feedback (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL REFERENCES auth_users(id) ON DELETE CASCADE,
                category VARCHAR(20) NOT NULL,
                description TEXT NOT NULL,
                context TEXT NOT NULL DEFAULT '{}',
                status VARCHAR(20) NOT NULL DEFAULT 'received',
                admin_response TEXT,
                github_issue_url VARCHAR(500),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_user_id
                ON feedback (user_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_category
                ON feedback (category)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_status
                ON feedback (status)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_user_created
                ON feedback (user_id, created_at)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS feedback (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                category VARCHAR(20) NOT NULL,
                description TEXT NOT NULL,
                context JSON NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'received',
                admin_response TEXT,
                github_issue_url VARCHAR(500),
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_feedback_user_id (user_id),
                INDEX idx_feedback_category (category),
                INDEX idx_feedback_status (status),
                INDEX idx_feedback_user_created (user_id, created_at),
                CONSTRAINT fk_feedback_user
                    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS feedback (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL REFERENCES auth_users(id) ON DELETE CASCADE,
                category VARCHAR(20) NOT NULL,
                description TEXT NOT NULL,
                context JSONB NOT NULL DEFAULT '{}',
                status VARCHAR(20) NOT NULL DEFAULT 'received',
                admin_response TEXT,
                github_issue_url VARCHAR(500),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_user_id
                ON feedback (user_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_category
                ON feedback (category)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_status
                ON feedback (status)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_feedback_user_created
                ON feedback (user_id, created_at)
            SQL);
    }
};
