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
            CREATE TABLE IF NOT EXISTS analytics_stats_daily (
                site_id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                visitors INT NOT NULL DEFAULT 0,
                pageviews INT NOT NULL DEFAULT 0,
                sessions INT NOT NULL DEFAULT 0,
                bounce_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                avg_duration DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                events_count INT NOT NULL DEFAULT 0,
                CONSTRAINT fk_stats_daily_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        // Composite primary key added separately for driver compatibility
        if ($driver === Driver::SQLite) {
            $indexes->ensure('analytics_stats_daily', 'pk_stats_daily', ['site_id', 'date'], unique: true);
        } else {
            $connection->execute(<<<'SQL'
                ALTER TABLE analytics_stats_daily ADD PRIMARY KEY (site_id, date)
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_stats_daily');
    }
};
