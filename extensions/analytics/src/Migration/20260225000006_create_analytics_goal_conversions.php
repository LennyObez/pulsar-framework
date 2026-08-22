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
            CREATE TABLE IF NOT EXISTS analytics_goal_conversions (
                id VARCHAR(36) NOT NULL,
                goal_id VARCHAR(36) NOT NULL,
                site_id VARCHAR(36) NOT NULL,
                visitor_id CHAR(64) NOT NULL,
                session_id CHAR(64) NOT NULL,
                revenue_value DECIMAL(10,2) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_conversions_goal FOREIGN KEY (goal_id) REFERENCES analytics_goals (id) ON DELETE CASCADE,
                CONSTRAINT fk_conversions_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure('analytics_goal_conversions', 'idx_conversions_goal_created', ['goal_id', 'created_at']);

        $indexes->ensure('analytics_goal_conversions', 'idx_conversions_site_created', ['site_id', 'created_at']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_goal_conversions');
    }
};
