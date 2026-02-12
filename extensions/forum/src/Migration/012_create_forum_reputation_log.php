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
            CREATE TABLE IF NOT EXISTS forum_reputation_log (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                action VARCHAR(50) NOT NULL,
                points INTEGER NOT NULL,
                source_id VARCHAR(36) DEFAULT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_reputation_log_user
                ON forum_reputation_log (user_id, created_at DESC)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_reputation_log_tenant
                ON forum_reputation_log (tenant_id, created_at DESC)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_reputation_log');
    }
};
