<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        // Covers ORDER BY c.published_at DESC in findPublished() queries.
        // The existing idx_content_status_tenant does not include published_at,
        // forcing a sort step on every published-content listing page.
        $connection->execute(<<<'SQL'
            CREATE INDEX idx_content_status_published_at
                ON cms_contents (status, published_at DESC)
                WHERE deleted_at IS NULL
            SQL);

        // Covers findTerms() queries that filter by taxonomy_id with optional
        // parent_id and ORDER BY sort_order. Without this, the planner falls
        // back to a sequential scan on cms_taxonomy_terms.
        $connection->execute(<<<'SQL'
            CREATE INDEX idx_taxonomy_term_parent_sort
                ON cms_taxonomy_terms (taxonomy_id, parent_id, sort_order)
            SQL);

        // Reverse index on the junction table. The PK (content_id, term_id)
        // only supports lookups by content_id. Queries that start from a term
        // (e.g., "find all content tagged with term X") require scanning the
        // full table without this index.
        $connection->execute(<<<'SQL'
            CREATE INDEX idx_content_taxonomy_term_reverse
                ON cms_content_taxonomy_terms (term_id)
            SQL);

        // Covers the ancestor-chain CTE query that walks parent_id references.
        // parent_id is already indexed as part of idx_content_parent_sort, but
        // the CTE also filters on deleted_at IS NULL; a partial index is more
        // selective for the recursive lookup.
        $connection->execute(<<<'SQL'
            CREATE INDEX idx_content_parent_active
                ON cms_contents (parent_id)
                WHERE deleted_at IS NULL AND parent_id IS NOT NULL
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP INDEX IF EXISTS idx_content_parent_active');
        $connection->execute('DROP INDEX IF EXISTS idx_content_taxonomy_term_reverse');
        $connection->execute('DROP INDEX IF EXISTS idx_taxonomy_term_parent_sort');
        $connection->execute('DROP INDEX IF EXISTS idx_content_status_published_at');
    }
};
