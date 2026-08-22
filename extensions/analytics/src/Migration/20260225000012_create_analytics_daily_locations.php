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
            CREATE TABLE IF NOT EXISTS analytics_daily_locations (
                site_id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                country_code CHAR(2) NOT NULL DEFAULT '',
                region VARCHAR(100) NOT NULL DEFAULT '',
                visitors INT NOT NULL DEFAULT 0,
                pageviews INT NOT NULL DEFAULT 0,
                CONSTRAINT fk_daily_locations_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure('analytics_daily_locations', 'idx_daily_locations_pk', ['site_id', 'date', 'country_code', 'region'], unique: true);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_daily_locations');
    }
};
