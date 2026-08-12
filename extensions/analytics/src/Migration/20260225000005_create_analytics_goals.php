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
            CREATE TABLE IF NOT EXISTS analytics_goals (
                id VARCHAR(36) NOT NULL,
                site_id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                goal_type VARCHAR(20) NOT NULL,
                target_value VARCHAR(500) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_goals_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure('analytics_goals', 'idx_goals_site', ['site_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_goals');
    }
};
