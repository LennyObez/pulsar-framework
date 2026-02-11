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
        $connection->execute('DROP TABLE IF EXISTS cms_form_submissions');
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_form_submissions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                form_block_id VARCHAR(64) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36),
                data TEXT NOT NULL DEFAULT '{}',
                ip_hash VARCHAR(128) NOT NULL,
                user_agent_hash VARCHAR(128) NOT NULL,
                submitted_at TEXT NOT NULL,
                evidence_hash VARCHAR(128) NOT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                is_spam INTEGER NOT NULL DEFAULT 0,
                spam_score REAL NOT NULL DEFAULT 0.0,
                spam_reason TEXT
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_content
                ON cms_form_submissions (tenant_id, content_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_spam
                ON cms_form_submissions (tenant_id, is_spam)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_form_submissions_submitted_at
                ON cms_form_submissions (submitted_at DESC)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_form_submissions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                form_block_id VARCHAR(64) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36),
                data JSON NOT NULL,
                ip_hash VARCHAR(128) NOT NULL,
                user_agent_hash VARCHAR(128) NOT NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                evidence_hash VARCHAR(128) NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                is_spam TINYINT(1) NOT NULL DEFAULT 0,
                spam_score DOUBLE NOT NULL DEFAULT 0.0,
                spam_reason TEXT,
                INDEX idx_form_submissions_tenant_content (tenant_id, content_id),
                INDEX idx_form_submissions_tenant_spam (tenant_id, is_spam),
                INDEX idx_form_submissions_submitted_at (submitted_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_form_submissions (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                form_block_id VARCHAR(64) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36),
                data JSONB NOT NULL DEFAULT '{}',
                ip_hash VARCHAR(128) NOT NULL,
                user_agent_hash VARCHAR(128) NOT NULL,
                submitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                evidence_hash VARCHAR(128) NOT NULL,
                is_read SMALLINT NOT NULL DEFAULT 0,
                is_spam SMALLINT NOT NULL DEFAULT 0,
                spam_score DOUBLE PRECISION NOT NULL DEFAULT 0.0,
                spam_reason TEXT
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_content
                ON cms_form_submissions (tenant_id, content_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_spam
                ON cms_form_submissions (tenant_id, is_spam)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_form_submissions_submitted_at
                ON cms_form_submissions (submitted_at DESC)
            SQL);
    }
};
