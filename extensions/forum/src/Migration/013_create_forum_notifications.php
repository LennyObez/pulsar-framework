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

        //: Notification inbox ------------------------------------------------
        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_notifications (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                type VARCHAR(100) NOT NULL,
                title VARCHAR(500) NOT NULL,
                body TEXT NOT NULL,
                url VARCHAR(2000) NOT NULL DEFAULT '',
                is_read BOOLEAN NOT NULL DEFAULT FALSE,
                data JSONB DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_notifications',
            'idx_notifications_user',
            ['user_id', IndexColumn::desc('created_at')],
        );

        // `is_read` stays in the key either way, so the predicate only narrows the index
        // where the engine has partial indexes and is dropped where it does not — which
        // is exactly the unfiltered index the MySQL branch used to spell out by hand.
        $indexes->ensure(
            'forum_notifications',
            'idx_notifications_unread',
            ['user_id', 'is_read'],
            where: 'is_read = FALSE',
        );

        //: Notification preferences ------------------------------------------
        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_notification_preferences (
                user_id VARCHAR(36) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                in_app BOOLEAN NOT NULL DEFAULT TRUE,
                email BOOLEAN NOT NULL DEFAULT FALSE,
                email_frequency VARCHAR(20) NOT NULL DEFAULT 'immediate',
                PRIMARY KEY (user_id, event_type)
            )
            SQL, $driver));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_notification_preferences');
        $connection->execute('DROP TABLE IF EXISTS forum_notifications');
    }
};
