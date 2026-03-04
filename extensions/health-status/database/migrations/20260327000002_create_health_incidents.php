<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $timestampType = match ($driver) {
            Driver::PostgreSQL => 'TIMESTAMPTZ',
            Driver::MySQL => 'DATETIME(3)',
            Driver::SQLite => 'TEXT',
        };

        $connection->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS health_incidents (
                id VARCHAR(36) NOT NULL,
                check_name VARCHAR(255) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                started_at {$timestampType} NOT NULL,
                acknowledged_at {$timestampType} NULL,
                resolved_at {$timestampType} NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_health_incidents_status ON health_incidents (status)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_health_incidents_started_at ON health_incidents (started_at)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_health_incidents_check_name ON health_incidents (check_name)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS health_incidents');
    }
};
