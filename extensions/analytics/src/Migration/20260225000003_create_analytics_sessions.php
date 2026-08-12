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
            CREATE TABLE IF NOT EXISTS analytics_sessions (
                id VARCHAR(36) NOT NULL,
                site_id VARCHAR(36) NOT NULL,
                visitor_id CHAR(64) NOT NULL,
                session_id CHAR(64) NOT NULL,
                entry_page VARCHAR(2048) NOT NULL,
                exit_page VARCHAR(2048) NOT NULL,
                page_count INT DEFAULT 1,
                duration_seconds INT DEFAULT 0,
                is_bounce TINYINT(1) DEFAULT 1,
                started_at TIMESTAMP NOT NULL,
                ended_at TIMESTAMP NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_sessions_site FOREIGN KEY (site_id) REFERENCES analytics_sites (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure('analytics_sessions', 'idx_session_site_session', ['site_id', 'session_id'], unique: true);

        $indexes->ensure('analytics_sessions', 'idx_session_site_started', ['site_id', 'started_at']);

        $indexes->ensure('analytics_sessions', 'idx_session_site_visitor', ['site_id', 'visitor_id']);

        // Composite index for SessionResolver::findActiveByVisitor() which queries
        // (site_id, visitor_id, ended_at >= ?): the three-column index covers
        // the exact predicate and avoids a full table scan.
        $indexes->ensure('analytics_sessions', 'idx_session_active_visitor', ['site_id', 'visitor_id', 'ended_at']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS analytics_sessions');
    }
};
