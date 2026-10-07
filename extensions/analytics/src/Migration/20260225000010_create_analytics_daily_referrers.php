<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Analytics\Migration\AnalyticsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $driver = $connection->driver();

        $connection->execute(AnalyticsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_daily_referrers (
                site_id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                referrer_source VARCHAR(255) NOT NULL,
                visitors INT NOT NULL DEFAULT 0,
                pageviews INT NOT NULL DEFAULT 0,
                CONSTRAINT fk_daily_referrers_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure('analytics_daily_referrers', 'idx_daily_referrers_pk', ['site_id', 'date', 'referrer_source'], unique: true);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_daily_referrers');
    }
};
