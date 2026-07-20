<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Analytics\Migration\AnalyticsDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        // One row per UTC day. The salt keys that day's visitor hashing and is
        // deleted after the retention window (VisitorSaltPurgeJob), which is
        // what makes older visitor hashes irreversible. day_number is the epoch
        // day (timestamp / 86400), so purging "older than" is a plain range.
        $connection->execute(AnalyticsDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analytics_visitor_salts (
                day_number INT NOT NULL,
                salt CHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL,
                PRIMARY KEY (day_number)
            )
            SQL, $driver));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_visitor_salts');
    }
};
