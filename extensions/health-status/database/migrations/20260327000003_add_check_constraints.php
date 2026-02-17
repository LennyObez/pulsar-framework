<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add CHECK constraints to health-status tables.
 *
 * Enforces valid enum values for overall_status, severity, and status columns
 * at the database level. SQLite does not support ALTER TABLE ADD CONSTRAINT,
 * so these rules are enforced at the application layer for SQLite deployments.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::PostgreSQL => $this->upPostgresql($connection),
            Driver::MySQL => $this->upMysql($connection),
            // SQLite does not support ALTER TABLE ADD CONSTRAINT.
            // Enum validation is enforced at the application layer.
            Driver::SQLite => null,
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::PostgreSQL => $this->downPostgresql($connection),
            Driver::MySQL => $this->downMysql($connection),
            Driver::SQLite => null,
        };
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE health_check_history
                ADD CONSTRAINT chk_overall_status
                CHECK (overall_status IN ('healthy', 'degraded', 'unhealthy'))
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents
                ADD CONSTRAINT chk_severity
                CHECK (severity IN ('minor', 'major', 'critical'))
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents
                ADD CONSTRAINT chk_status
                CHECK (status IN ('open', 'acknowledged', 'resolved'))
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE health_check_history
                ADD CONSTRAINT chk_overall_status
                CHECK (overall_status IN ('healthy', 'degraded', 'unhealthy'))
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents
                ADD CONSTRAINT chk_severity
                CHECK (severity IN ('minor', 'major', 'critical'))
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents
                ADD CONSTRAINT chk_status
                CHECK (status IN ('open', 'acknowledged', 'resolved'))
            SQL);
    }

    private function downPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE health_check_history DROP CONSTRAINT IF EXISTS chk_overall_status
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents DROP CONSTRAINT IF EXISTS chk_severity
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents DROP CONSTRAINT IF EXISTS chk_status
            SQL);
    }

    private function downMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE health_check_history DROP CHECK chk_overall_status
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents DROP CHECK chk_severity
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE health_incidents DROP CHECK chk_status
            SQL);
    }
};
