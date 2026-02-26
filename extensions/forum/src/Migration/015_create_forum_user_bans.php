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

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_user_bans (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                banned_by VARCHAR(36) NOT NULL,
                reason TEXT NOT NULL,
                type VARCHAR(20) NOT NULL,
                expires_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                revoked_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_bans_user
                ON forum_user_bans (user_id, created_at DESC)
            SQL);

        match ($driver) {
            Driver::PostgreSQL, Driver::SQLite => $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_bans_active
                    ON forum_user_bans (user_id)
                    WHERE revoked_at IS NULL
                SQL),
            Driver::MySQL => $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_bans_active
                    ON forum_user_bans (user_id, revoked_at)
                SQL),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_user_bans');
    }
};
