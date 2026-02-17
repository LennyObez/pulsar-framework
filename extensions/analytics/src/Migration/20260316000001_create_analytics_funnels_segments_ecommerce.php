<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Creates tables for GA4-level analytics features:
 * funnels, segments, e-commerce transactions.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        // Funnels
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_funnels (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                site_id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                steps TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        // Segments
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_segments (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                site_id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                filters TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        // E-commerce transactions
        $connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_ecommerce_transactions (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                site_id VARCHAR(36) NOT NULL,
                visitor_id VARCHAR(64) NOT NULL,
                session_id VARCHAR(64) NOT NULL,
                order_id VARCHAR(255) NOT NULL,
                revenue DECIMAL(12, 2) NOT NULL DEFAULT 0,
                tax DECIMAL(12, 2) NOT NULL DEFAULT 0,
                shipping DECIMAL(12, 2) NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                items TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        // Indexes (separate for SQLite compatibility)
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_funnels_site ON analytics_funnels (site_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_segments_site ON analytics_segments (site_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_ecom_site_date ON analytics_ecommerce_transactions (site_id, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_ecom_visitor ON analytics_ecommerce_transactions (visitor_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_ecom_order ON analytics_ecommerce_transactions (order_id)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_ecommerce_transactions');
        $connection->execute('DROP TABLE IF EXISTS analytics_segments');
        $connection->execute('DROP TABLE IF EXISTS analytics_funnels');
    }
};
