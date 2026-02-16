<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Forum\Migration\ForumDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

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

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notifications_user
                ON forum_notifications (user_id, created_at DESC)
            SQL);

        match ($driver) {
            Driver::PostgreSQL, Driver::SQLite => $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_notifications_unread
                    ON forum_notifications (user_id, is_read)
                    WHERE is_read = FALSE
                SQL),
            Driver::MySQL => $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_notifications_unread
                    ON forum_notifications (user_id, is_read)
                SQL),
        };

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
