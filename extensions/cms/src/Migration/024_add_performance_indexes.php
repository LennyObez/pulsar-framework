<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexColumn;
use Pulsar\Database\Schema\IndexOperations;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        // Covers ORDER BY c.published_at DESC in findPublished() queries.
        $indexes->ensure(
            'cms_contents',
            'idx_content_status_published_at',
            ['status', IndexColumn::desc('published_at')],
            where: 'deleted_at IS NULL',
        );

        // Covers findTerms() queries that filter by taxonomy_id with optional
        // parent_id and ORDER BY sort_order.
        $indexes->ensure(
            'cms_taxonomy_terms',
            'idx_taxonomy_term_parent_sort',
            ['taxonomy_id', 'parent_id', 'sort_order'],
        );

        // Reverse index on the junction table.
        $indexes->ensure(
            'cms_content_taxonomy_terms',
            'idx_content_taxonomy_term_reverse',
            ['term_id'],
        );

        // Covers the ancestor-chain CTE query that walks parent_id references. An engine
        // without partial indexes has to carry deleted_at in the key instead.
        $indexes->ensure(
            'cms_contents',
            'idx_content_parent_active',
            $connection->dialect()->supportsPartialIndexes()
                ? ['parent_id']
                : ['parent_id', 'deleted_at'],
            where: 'deleted_at IS NULL AND parent_id IS NOT NULL',
        );
    }

    /**
     * MySQL drops an index through the table that owns it and accepts no `IF EXISTS`, so
     * the standard form written by hand here failed there — on a path that runs rarely
     * enough for nobody to notice. The index layer knows each engine's spelling, and
     * establishes the index is there before naming it: an `up()` that died partway leaves
     * some of these missing, and MySQL errors on a drop of one that is not.
     */
    public function down(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        foreach (
            [
                'idx_content_parent_active' => 'cms_contents',
                'idx_content_taxonomy_term_reverse' => 'cms_content_taxonomy_terms',
                'idx_taxonomy_term_parent_sort' => 'cms_taxonomy_terms',
                'idx_content_status_published_at' => 'cms_contents',
            ] as $index => $table
        ) {
            $indexes->ensureAbsent($table, $index);
        }
    }
};
