<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        // Covers ORDER BY c.published_at DESC in findPublished() queries.
        // Partial index with DESC: PostgreSQL + SQLite support it; MySQL fallback
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_content_status_published_at
                    ON cms_contents (status, published_at)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_content_status_published_at
                    ON cms_contents (status, published_at DESC)
                    WHERE deleted_at IS NULL
                SQL);
        }

        // Covers findTerms() queries that filter by taxonomy_id with optional
        // parent_id and ORDER BY sort_order.
        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_taxonomy_term_parent_sort
                ON cms_taxonomy_terms (taxonomy_id, parent_id, sort_order)
            SQL);

        // Reverse index on the junction table.
        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_content_taxonomy_term_reverse
                ON cms_content_taxonomy_terms (term_id)
            SQL);

        // Covers the ancestor-chain CTE query that walks parent_id references.
        // Partial index: PostgreSQL + SQLite support it; MySQL fallback
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_content_parent_active
                    ON cms_contents (parent_id, deleted_at)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_content_parent_active
                    ON cms_contents (parent_id)
                    WHERE deleted_at IS NULL AND parent_id IS NOT NULL
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP INDEX IF EXISTS idx_content_parent_active');
        $connection->execute('DROP INDEX IF EXISTS idx_content_taxonomy_term_reverse');
        $connection->execute('DROP INDEX IF EXISTS idx_taxonomy_term_parent_sort');
        $connection->execute('DROP INDEX IF EXISTS idx_content_status_published_at');
    }
};
