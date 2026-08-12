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
            CREATE TABLE IF NOT EXISTS forum_profiles (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                user_id VARCHAR(36) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                bio TEXT DEFAULT NULL,
                avatar_url VARCHAR(500) DEFAULT NULL,
                reputation_score INTEGER NOT NULL DEFAULT 0,
                thread_count INTEGER NOT NULL DEFAULT 0,
                post_count INTEGER NOT NULL DEFAULT 0,
                solution_count INTEGER NOT NULL DEFAULT 0,
                is_banned BOOLEAN NOT NULL DEFAULT FALSE,
                banned_at TIMESTAMPTZ DEFAULT NULL,
                banned_reason TEXT DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )
            SQL, $driver));

        // Keyed on an expression, which IndexOperations has no way to express — its columns
        // are quoted as identifiers. Still unrunnable on MySQL, which rejects `IF NOT EXISTS`
        // here and wants a functional key part in parentheses of its own besides.
        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_profile_user_tenant
                ON forum_profiles (user_id, COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'))
            SQL);

        $indexes->ensure(
            'forum_profiles',
            'idx_profile_reputation',
            [IndexColumn::desc('reputation_score')],
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_profiles');
    }
};
