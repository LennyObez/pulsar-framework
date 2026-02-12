<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Forum\Migration\ForumDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_threads (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                category_id VARCHAR(36) NOT NULL,
                author_id VARCHAR(36) NOT NULL,
                title VARCHAR(200) NOT NULL,
                slug VARCHAR(250) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'discussion',
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
                is_locked BOOLEAN NOT NULL DEFAULT FALSE,
                solved_post_id VARCHAR(36) DEFAULT NULL,
                reply_count INTEGER NOT NULL DEFAULT 0,
                view_count INTEGER NOT NULL DEFAULT 0,
                vote_score INTEGER NOT NULL DEFAULT 0,
                last_activity_at TIMESTAMPTZ DEFAULT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_thread_category FOREIGN KEY (category_id) REFERENCES forum_categories (id) ON DELETE CASCADE,
                CONSTRAINT chk_thread_type CHECK (type IN ('discussion', 'question', 'bug_report', 'feature_request', 'showcase', 'announcement')),
                CONSTRAINT chk_thread_status CHECK (status IN ('open', 'closed', 'locked'))
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_thread_slug_tenant
                ON forum_threads (slug, COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'))
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_thread_category_activity
                ON forum_threads (category_id, is_pinned DESC, last_activity_at DESC)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_thread_author
                ON forum_threads (author_id, created_at DESC)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_thread_tenant_activity
                ON forum_threads (tenant_id, is_pinned DESC, last_activity_at DESC)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_threads');
    }
};
