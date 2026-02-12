<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Analytics\Migration\AnalyticsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $connection->execute(AnalyticsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_events (
                id VARCHAR(36) NOT NULL,
                site_id VARCHAR(36) NOT NULL,
                visitor_id CHAR(64) NOT NULL,
                session_id CHAR(64) NOT NULL,
                event_name VARCHAR(255) NOT NULL,
                event_props JSON DEFAULT NULL,
                revenue_value DECIMAL(10,2) DEFAULT NULL,
                pathname VARCHAR(2048) DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_events_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_events_site_name_created ON analytics_events (site_id, event_name, created_at)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_events_site_created ON analytics_events (site_id, created_at)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_events');
    }
};
