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
            CREATE TABLE IF NOT EXISTS forum_thread_subscriptions (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT fk_subscription_thread FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_thread_subscriptions',
            'uq_subscription_user_thread',
            ['user_id', 'thread_id'],
            unique: true,
        );

        $indexes->ensure('forum_thread_subscriptions', 'idx_subscription_thread', ['thread_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_thread_subscriptions');
    }
};
