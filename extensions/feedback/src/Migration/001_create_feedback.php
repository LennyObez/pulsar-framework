<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

/**
 * The table is still written once per engine — the column types genuinely differ, and
 * MySQL needs a named constraint where the other two inline the reference. The indexes
 * did not differ, and were three copies of one list waiting to disagree: MySQL's lived
 * inside its `CREATE TABLE`, so nothing would have compared it against the other two.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };

        $indexes = new IndexOperations($connection);

        $indexes->ensure('feedback', 'idx_feedback_user_id', ['user_id']);
        $indexes->ensure('feedback', 'idx_feedback_category', ['category']);
        $indexes->ensure('feedback', 'idx_feedback_status', ['status']);
        $indexes->ensure('feedback', 'idx_feedback_user_created', ['user_id', 'created_at']);
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
    }
};
