<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add CHECK constraints to analytics tables for data integrity.
 *
 * Enforces non-negative values for screen_width, page_count, and
 * duration_seconds at the database level. SQLite does not support
 * ADD CONSTRAINT on existing tables, so these rules are enforced
 * at the application level for SQLite deployments.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::PostgreSQL => $this->upPostgresql($connection),
            Driver::MySQL => $this->upMysql($connection),
            // SQLite does not support ALTER TABLE ADD CONSTRAINT.
            // These constraints are enforced at the application layer.
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
            ALTER TABLE analytics_page_views
                ADD CONSTRAINT chk_screen_width CHECK (screen_width > 0 OR screen_width IS NULL)
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions
                ADD CONSTRAINT chk_page_count CHECK (page_count >= 0)
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions
                ADD CONSTRAINT chk_duration CHECK (duration_seconds >= 0)
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_page_views
                ADD CONSTRAINT chk_screen_width CHECK (screen_width > 0 OR screen_width IS NULL)
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions
                ADD CONSTRAINT chk_page_count CHECK (page_count >= 0)
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions
                ADD CONSTRAINT chk_duration CHECK (duration_seconds >= 0)
            SQL);
    }

    private function downPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_page_views DROP CONSTRAINT IF EXISTS chk_screen_width
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions DROP CONSTRAINT IF EXISTS chk_page_count
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions DROP CONSTRAINT IF EXISTS chk_duration
            SQL);
    }

    private function downMysql(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_page_views DROP CHECK chk_screen_width
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions DROP CHECK chk_page_count
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE analytics_sessions DROP CHECK chk_duration
            SQL);
    }
};
