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
            CREATE TABLE IF NOT EXISTS forum_tags (
                id VARCHAR(36) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                usage_count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_tag_slug ON forum_tags (slug)
            SQL);

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_tags (
                tag_id VARCHAR(36) NOT NULL,
                thread_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (tag_id, thread_id),
                CONSTRAINT fk_thread_tag_tag FOREIGN KEY (tag_id) REFERENCES forum_tags (id) ON DELETE CASCADE,
                CONSTRAINT fk_thread_tag_thread FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_thread_tags_thread ON forum_thread_tags (thread_id)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_thread_tags');
        $connection->execute('DROP TABLE IF EXISTS forum_tags');
    }
};
