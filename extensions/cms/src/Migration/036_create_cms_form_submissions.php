<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexColumn;
use Pulsar\Database\Schema\IndexOperations;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };

        $this->createIndexes($connection);
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
                spam_reason TEXT
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
    }

    /**
     * The same three indexes were written once per engine, inline on MySQL and as
     * `CREATE INDEX IF NOT EXISTS` on the other two — a clause MySQL rejects outright,
     * which is why the copies could never have been one statement. Stated once here,
     * the dialect spells each engine's version.
     */
    private function createIndexes(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);
        $table = 'cms_form_submissions';

        $indexes->ensure($table, 'idx_form_submissions_tenant_content', ['tenant_id', 'content_id']);
        $indexes->ensure($table, 'idx_form_submissions_tenant_spam', ['tenant_id', 'is_spam']);
        // Submissions are read newest first, which a descending index scans forwards.
        $indexes->ensure($table, 'idx_form_submissions_submitted_at', [IndexColumn::desc('submitted_at')]);
    }
};
