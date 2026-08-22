<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_comments (
                id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                content_id VARCHAR(36) NOT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                author_id VARCHAR(36) DEFAULT NULL,
                guest_name VARCHAR(200) DEFAULT NULL,
                guest_email VARCHAR(320) DEFAULT NULL,
                body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                ip_hash VARCHAR(128) NOT NULL,
                user_agent_hash VARCHAR(128) NOT NULL,
                edited_at TIMESTAMPTZ DEFAULT NULL,
                edit_window_expires_at TIMESTAMPTZ DEFAULT NULL,
                data_classification VARCHAR(20) NOT NULL DEFAULT 'pii',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_comment_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                CONSTRAINT fk_comment_parent FOREIGN KEY (parent_id) REFERENCES cms_comments (id) ON DELETE CASCADE,
                CONSTRAINT chk_comment_status CHECK (status IN ('pending', 'approved', 'rejected', 'spam')),
                CONSTRAINT chk_comment_guest_identity CHECK (author_id IS NOT NULL OR guest_name IS NOT NULL),
                CONSTRAINT chk_comment_data_classification CHECK (data_classification IN ('public', 'internal', 'confidential', 'pii'))
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_comments',
            'idx_comment_content_status_created',
            ['content_id', 'status', 'created_at'],
        );

        // `status` stays in the key rather than only in the predicate, so an engine
        // without partial indexes still narrows to the moderation queue on its own.
        $indexes->ensure(
            'cms_comments',
            'idx_comment_pending_tenant',
            ['status', 'tenant_id'],
            where: "status = 'pending'",
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_comments');
    }
};
