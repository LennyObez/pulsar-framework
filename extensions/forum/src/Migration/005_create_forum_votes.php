<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Forum\Migration\ForumDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_votes (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                value SMALLINT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_thread_vote_thread FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE,
                CONSTRAINT chk_thread_vote_value CHECK (value IN (1, -1))
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_thread_votes',
            'uq_thread_vote_user',
            ['thread_id', 'user_id'],
            unique: true,
        );

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_post_votes (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                post_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                value SMALLINT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_post_vote_post FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE,
                CONSTRAINT chk_post_vote_value CHECK (value IN (1, -1))
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_post_votes',
            'uq_post_vote_user',
            ['post_id', 'user_id'],
            unique: true,
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_post_votes');
        $connection->execute('DROP TABLE IF EXISTS forum_thread_votes');
    }
};
