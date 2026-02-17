<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add missing foreign key index on analytics_page_views.visitor_id.
 *
 * The visitor_id column is used for per-visitor lookups and aggregation
 * but only had composite indexes (site_id, visitor_id). A standalone index
 * on visitor_id improves cross-site visitor queries and DELETE cascades.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::MySQL => $connection->execute(
                'CREATE INDEX idx_page_views_visitor ON analytics_page_views (visitor_id)',
            ),
            Driver::SQLite, Driver::PostgreSQL => $connection->execute(
                'CREATE INDEX IF NOT EXISTS idx_page_views_visitor ON analytics_page_views (visitor_id)',
            ),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::MySQL => $connection->execute(
                'ALTER TABLE analytics_page_views DROP INDEX idx_page_views_visitor',
            ),
            Driver::SQLite, Driver::PostgreSQL => $connection->execute(
                'DROP INDEX IF EXISTS idx_page_views_visitor',
            ),
        };
    }
};
