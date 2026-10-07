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
            CREATE TABLE IF NOT EXISTS forum_categories (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                name VARCHAR(200) NOT NULL DEFAULT '',
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

        // Keyed on an expression, which IndexOperations has no way to express — its columns
        // are quoted as identifiers. Still unrunnable on MySQL, which rejects `IF NOT EXISTS`
        // here and wants a functional key part in parentheses of its own besides.
        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_category_slug_tenant
                ON forum_categories (slug, COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'))
            SQL);

        $indexes->ensure('forum_categories', 'idx_category_parent_sort', ['parent_id', 'sort_order']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_categories');
    }
};
