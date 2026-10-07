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
            CREATE TABLE IF NOT EXISTS cms_editorial_reviews (
                id VARCHAR(36) NOT NULL,
                content_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) DEFAULT NULL,
                requested_by VARCHAR(36) NOT NULL,
                reviewer_id VARCHAR(36) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                comment TEXT DEFAULT NULL,
                decision_reason VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                decided_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_review_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                CONSTRAINT chk_review_status CHECK (status IN ('pending', 'in_review', 'approved', 'rejected', 'cancelled'))
            )
            SQL, $driver));

        $indexes->ensure(
            'cms_editorial_reviews',
            'idx_review_content_status',
            ['content_id', 'status'],
        );

        // `status` stays in the key rather than only in the predicate, so an engine
        // without partial indexes still narrows to the open reviews on its own.
        $indexes->ensure(
            'cms_editorial_reviews',
            'idx_review_reviewer_active',
            ['reviewer_id', 'status'],
            where: "status IN ('pending', 'in_review')",
        );

        $connection->execute(CmsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_content_locks (
                content_id VARCHAR(36) NOT NULL,
                locked_by VARCHAR(36) NOT NULL,
                locked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMPTZ NOT NULL,
                locale VARCHAR(5) DEFAULT NULL,
                PRIMARY KEY (content_id),
                CONSTRAINT fk_lock_content FOREIGN KEY (content_id) REFERENCES cms_contents (id) ON DELETE CASCADE,
                CONSTRAINT chk_lock_expiry_after_acquire CHECK (expires_at > locked_at)
            )
            SQL, $driver));

        $indexes->ensure('cms_content_locks', 'idx_lock_expires', ['expires_at']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_locks');
        $connection->execute('DROP TABLE IF EXISTS cms_editorial_reviews');
    }
};
