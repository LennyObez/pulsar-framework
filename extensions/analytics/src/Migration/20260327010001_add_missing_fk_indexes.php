<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add missing foreign key index on analytics_page_views.visitor_id.
 *
 * The visitor_id column is used for per-visitor lookups and aggregation
 * but only had composite indexes (site_id, visitor_id). A standalone index
 * on visitor_id improves cross-site visitor queries and DELETE cascades.
 */
return new class implements MigrationInterface {
    private const string INDEX = 'idx_page_views_visitor';
    private const string TABLE = 'analytics_page_views';

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(
            $connection->dialect()->compileCreateIndex(
                self::INDEX,
                self::TABLE,
                ['visitor_id'],
                ifNotExists: true,
            ),
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute(
            $connection->dialect()->compileDropIndex(self::INDEX, self::TABLE),
        );
    }
};
