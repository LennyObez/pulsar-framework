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
            CREATE TABLE IF NOT EXISTS forum_posts (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                author_id VARCHAR(36) NOT NULL,
                body TEXT NOT NULL,
                body_html TEXT NOT NULL,
                is_solution BOOLEAN NOT NULL DEFAULT FALSE,
                vote_score INTEGER NOT NULL DEFAULT 0,
                edit_count INTEGER NOT NULL DEFAULT 0,
                edited_by VARCHAR(36) DEFAULT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                edited_at TIMESTAMPTZ DEFAULT NULL,
                edit_window_expires_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_post_thread FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE,
                CONSTRAINT fk_post_parent FOREIGN KEY (parent_id) REFERENCES forum_posts (id) ON DELETE SET NULL
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_posts',
            'idx_post_thread_created',
            ['thread_id', new IndexColumn('created_at', descending: false)],
        );

        $indexes->ensure(
            'forum_posts',
            'idx_post_author',
            ['author_id', IndexColumn::desc('created_at')],
        );

        $indexes->ensure('forum_posts', 'idx_post_parent', ['parent_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_posts');
    }
};
