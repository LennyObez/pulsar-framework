<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Forum\Migration\ForumDdl;

/**
 * Add soft-delete (deleted_at) column to forum_tags.
 *
 * Tags should be soft-deleted to preserve referential integrity with
 * forum_thread_tags and to support audit trails.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        $columnType = ForumDdl::adapt('TIMESTAMPTZ', $driver);

        $connection->execute(
            "ALTER TABLE forum_tags ADD COLUMN deleted_at {$columnType} DEFAULT NULL",
        );

        match ($driver) {
            Driver::MySQL => $connection->execute(
                'CREATE INDEX idx_forum_tags_deleted ON forum_tags (deleted_at)',
            ),
            Driver::SQLite, Driver::PostgreSQL => $connection->execute(
                'CREATE INDEX IF NOT EXISTS idx_forum_tags_deleted ON forum_tags (deleted_at)',
            ),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::SQLite => $connection->execute(
                'DROP INDEX IF EXISTS idx_forum_tags_deleted',
            ),
            Driver::MySQL => $connection->execute(
                'ALTER TABLE forum_tags DROP INDEX idx_forum_tags_deleted',
            ),
            Driver::PostgreSQL => $connection->execute(
                'DROP INDEX IF EXISTS idx_forum_tags_deleted',
            ),
        };

        match ($driver) {
            Driver::SQLite => null,
            Driver::MySQL => $connection->execute(
                'ALTER TABLE forum_tags DROP COLUMN deleted_at',
            ),
            Driver::PostgreSQL => $connection->execute(
                'ALTER TABLE forum_tags DROP COLUMN IF EXISTS deleted_at',
            ),
        };
    }
};
