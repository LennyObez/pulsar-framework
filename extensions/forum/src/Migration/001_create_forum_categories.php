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
            CREATE TABLE IF NOT EXISTS forum_categories (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_locked BOOLEAN NOT NULL DEFAULT FALSE,
                thread_count INTEGER NOT NULL DEFAULT 0,
                post_count INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES forum_categories (id) ON DELETE SET NULL,
                CONSTRAINT chk_category_no_self_parent CHECK (parent_id IS NULL OR parent_id != id)
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_category_slug_tenant
                ON forum_categories (slug, COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'))
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_category_parent_sort
                ON forum_categories (parent_id, sort_order)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_categories');
    }
};
