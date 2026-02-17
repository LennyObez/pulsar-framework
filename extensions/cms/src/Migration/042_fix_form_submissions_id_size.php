<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Fix cms_form_submissions.id column size.
 *
 * The original migration (036) defined id as VARCHAR(32), which is too small
 * for standard UUIDs with hyphens (36 characters). This migration widens the
 * column to VARCHAR(36) for MySQL and PostgreSQL. SQLite requires a full table
 * rebuild since it does not support ALTER COLUMN.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $connection->execute(
                'ALTER TABLE cms_form_submissions MODIFY COLUMN id VARCHAR(36) NOT NULL',
            ),
            Driver::PostgreSQL => $connection->execute(
                'ALTER TABLE cms_form_submissions ALTER COLUMN id TYPE VARCHAR(36)',
            ),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $this->downSqlite($connection),
            Driver::MySQL => $connection->execute(
                'ALTER TABLE cms_form_submissions MODIFY COLUMN id VARCHAR(32) NOT NULL',
            ),
            Driver::PostgreSQL => $connection->execute(
                'ALTER TABLE cms_form_submissions ALTER COLUMN id TYPE VARCHAR(32)',
            ),
        };
    }

    /**
     * SQLite cannot ALTER COLUMN, so we rebuild the table with the correct size.
     */
    private function upSqlite(ConnectionInterface $connection): void
    {
        // Transaction managed by MigrationRunner (no explicit BEGIN needed)

        $connection->execute(<<<'SQL'
            CREATE TABLE cms_form_submissions_new (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
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
            INSERT INTO cms_form_submissions_new
                SELECT * FROM cms_form_submissions
            SQL);

        $connection->execute('DROP TABLE cms_form_submissions');
        $connection->execute('ALTER TABLE cms_form_submissions_new RENAME TO cms_form_submissions');

        // Recreate indexes lost during table rebuild
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

        // Commit managed by MigrationRunner
    }

    /**
     * Reverse the SQLite rebuild (shrink back to VARCHAR(32)).
     */
    private function downSqlite(ConnectionInterface $connection): void
    {
        // Transaction managed by MigrationRunner (no explicit BEGIN needed)

        $connection->execute(<<<'SQL'
            CREATE TABLE cms_form_submissions_old (
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
            INSERT INTO cms_form_submissions_old
                SELECT * FROM cms_form_submissions
            SQL);

        $connection->execute('DROP TABLE cms_form_submissions');
        $connection->execute('ALTER TABLE cms_form_submissions_old RENAME TO cms_form_submissions');

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

        // Commit managed by MigrationRunner
    }
};
