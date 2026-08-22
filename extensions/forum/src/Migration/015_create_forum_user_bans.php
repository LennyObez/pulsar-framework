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

        $indexes->ensure(
            'forum_user_bans',
            'idx_bans_user',
            ['user_id', IndexColumn::desc('created_at')],
        );

        // An engine without partial indexes cannot filter on `revoked_at`, so it has to
        // carry it in the key instead.
        $indexes->ensure(
            'forum_user_bans',
            'idx_bans_active',
            $connection->dialect()->supportsPartialIndexes()
                ? ['user_id']
                : ['user_id', 'revoked_at'],
            where: 'revoked_at IS NULL',
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_user_bans');
    }
};
