<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $timestampType = match ($driver) {
            Driver::PostgreSQL => 'TIMESTAMPTZ',
            Driver::MySQL => 'DATETIME(3)',
            Driver::SQLite => 'TEXT',
        };

        $floatType = match ($driver) {
            Driver::PostgreSQL => 'DOUBLE PRECISION',
            Driver::MySQL => 'DOUBLE',
            Driver::SQLite => 'REAL',
        };

        $connection->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS health_check_history (
                id VARCHAR(36) NOT NULL,
                overall_status VARCHAR(20) NOT NULL,
                results_json TEXT NOT NULL,
                total_duration_ms {$floatType} NOT NULL,
                captured_at {$timestampType} NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $indexes->ensure('health_check_history', 'idx_health_history_captured_at', ['captured_at']);
        $indexes->ensure('health_check_history', 'idx_health_history_status', ['overall_status']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS health_check_history');
    }
};
