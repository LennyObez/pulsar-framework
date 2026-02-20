<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Analytics\Migration\AnalyticsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(AnalyticsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_stats_hourly (
                site_id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                hour INT NOT NULL,
                visitors INT NOT NULL DEFAULT 0,
                pageviews INT NOT NULL DEFAULT 0,
                sessions INT NOT NULL DEFAULT 0,
                bounce_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                avg_duration DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                events_count INT NOT NULL DEFAULT 0,
                CONSTRAINT fk_stats_hourly_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        if ($driver === Driver::SQLite) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS pk_stats_hourly ON analytics_stats_hourly (site_id, date, hour)
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                ALTER TABLE analytics_stats_hourly ADD PRIMARY KEY (site_id, date, hour)
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_stats_hourly');
    }
};
