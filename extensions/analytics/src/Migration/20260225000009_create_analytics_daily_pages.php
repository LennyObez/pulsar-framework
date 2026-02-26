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
            CREATE TABLE IF NOT EXISTS analytics_daily_pages (
                site_id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                pathname VARCHAR(2048) NOT NULL,
                visitors INT NOT NULL DEFAULT 0,
                pageviews INT NOT NULL DEFAULT 0,
                entries INT NOT NULL DEFAULT 0,
                exits INT NOT NULL DEFAULT 0,
                avg_time_on_page DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                CONSTRAINT fk_daily_pages_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        if ($driver === Driver::MySQL) {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX idx_daily_pages_pk ON analytics_daily_pages (site_id, date, pathname(255))
                SQL);
        } else {
            $connection->execute(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS idx_daily_pages_pk ON analytics_daily_pages (site_id, date, pathname)
                SQL);
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_daily_pages');
    }
};
