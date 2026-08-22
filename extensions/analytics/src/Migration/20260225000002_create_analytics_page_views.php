<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Analytics\Migration\AnalyticsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $driver = $connection->driver();

        $connection->execute(AnalyticsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_page_views (
                id VARCHAR(36) NOT NULL,
                site_id VARCHAR(36) NOT NULL,
                visitor_id CHAR(64) NOT NULL,
                session_id CHAR(64) NOT NULL,
                pathname VARCHAR(2048) NOT NULL,
                referrer_source VARCHAR(255) DEFAULT '',
                utm_source VARCHAR(255) DEFAULT '',
                utm_medium VARCHAR(255) DEFAULT '',
                utm_campaign VARCHAR(255) DEFAULT '',
                utm_term VARCHAR(255) DEFAULT '',
                utm_content VARCHAR(255) DEFAULT '',
                country_code CHAR(2) DEFAULT '',
                device_type VARCHAR(10) DEFAULT 'unknown',
                browser VARCHAR(50) DEFAULT '',
                os VARCHAR(50) DEFAULT '',
                screen_width INT DEFAULT 0,
                is_bounce TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_page_views_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure('analytics_page_views', 'idx_pv_site_created', ['site_id', 'created_at']);

        $indexes->ensure('analytics_page_views', 'idx_pv_site_visitor_created', ['site_id', 'visitor_id', 'created_at']);

        // pathname prefix index: MySQL uses KEY(col(N)), others index full column
        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_pv_site_pathname_created ON analytics_page_views (site_id, pathname(255), created_at)
                SQL);
        } else {
            $indexes->ensure('analytics_page_views', 'idx_pv_site_pathname_created', ['site_id', 'pathname', 'created_at']);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_page_views');
    }
};
