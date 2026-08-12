<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexColumn;
use Pulsar\Database\Schema\IndexOperations;

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
        $this->recreateIndexes($connection);

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

        $this->recreateIndexes($connection);

        // Commit managed by MigrationRunner
    }

    /**
     * The indexes migration 036 puts on the table, restated because dropping the table
     * takes them with it. Both directions rebuild, so both need them; they were written
     * out twice here and have to stay identical to 036's list to survive the round trip.
     */
    private function recreateIndexes(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);
        $table = 'cms_form_submissions';

        $indexes->ensure($table, 'idx_form_submissions_tenant_content', ['tenant_id', 'content_id']);
        $indexes->ensure($table, 'idx_form_submissions_tenant_spam', ['tenant_id', 'is_spam']);
        $indexes->ensure($table, 'idx_form_submissions_submitted_at', [IndexColumn::desc('submitted_at')]);
    }
};
