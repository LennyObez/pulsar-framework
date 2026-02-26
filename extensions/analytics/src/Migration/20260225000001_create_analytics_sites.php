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
            CREATE TABLE IF NOT EXISTS analytics_sites (
                id VARCHAR(36) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                tracking_id VARCHAR(20) NOT NULL,
                timezone VARCHAR(50) NOT NULL DEFAULT 'UTC',
                settings JSON DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT uq_analytics_sites_domain UNIQUE (domain),
                CONSTRAINT uq_analytics_sites_tracking_id UNIQUE (tracking_id)
            )
            SQL, $driver));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_sites');
    }
};
