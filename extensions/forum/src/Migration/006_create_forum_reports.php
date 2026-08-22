<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexColumn;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Forum\Migration\ForumDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_reports (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                reporter_id VARCHAR(36) NOT NULL,
                reason TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                reviewed_by VARCHAR(36) DEFAULT NULL,
                resolution_note TEXT DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_thread_report_thread FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE,
                CONSTRAINT chk_thread_report_status CHECK (status IN ('pending', 'under_review', 'resolved', 'dismissed'))
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_thread_reports',
            'idx_thread_report_status',
            ['status', IndexColumn::desc('created_at')],
        );

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_post_reports (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                post_id VARCHAR(36) NOT NULL,
                reporter_id VARCHAR(36) NOT NULL,
                reason TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                reviewed_by VARCHAR(36) DEFAULT NULL,
                resolution_note TEXT DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_post_report_post FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE,
                CONSTRAINT chk_post_report_status CHECK (status IN ('pending', 'under_review', 'resolved', 'dismissed'))
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_post_reports',
            'idx_post_report_status',
            ['status', IndexColumn::desc('created_at')],
        );

        // Unified view used by ForumReportResource for admin panel listing
        $connection->execute(<<<'SQL'
            CREATE VIEW IF NOT EXISTS forum_reports AS
            SELECT id, tenant_id, 'thread' AS target_type, thread_id AS target_id,
                   reporter_id, reason, status, reviewed_by AS moderator_id,
                   resolution_note AS moderator_note, updated_at AS reviewed_at, created_at
            FROM forum_thread_reports
            UNION ALL
            SELECT id, tenant_id, 'post' AS target_type, post_id AS target_id,
                   reporter_id, reason, status, reviewed_by AS moderator_id,
                   resolution_note AS moderator_note, updated_at AS reviewed_at, created_at
            FROM forum_post_reports
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP VIEW IF EXISTS forum_reports');
        $connection->execute('DROP TABLE IF EXISTS forum_post_reports');
        $connection->execute('DROP TABLE IF EXISTS forum_thread_reports');
    }
};
