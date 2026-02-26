<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Cms\Migration\CmsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

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

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_review_content_status
                ON cms_editorial_reviews (content_id, status)
            SQL);

        // Partial index: supported by PostgreSQL and SQLite, fallback for MySQL
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_review_reviewer_active
                    ON cms_editorial_reviews (reviewer_id, status)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_review_reviewer_active
                    ON cms_editorial_reviews (reviewer_id, status)
                    WHERE status IN ('pending', 'in_review')
                SQL);
        }

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

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_lock_expires ON cms_content_locks (expires_at)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS cms_content_locks');
        $connection->execute('DROP TABLE IF EXISTS cms_editorial_reviews');
    }
};
