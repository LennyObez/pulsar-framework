<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add missing foreign key indexes to forum_posts.
 *
 * The edited_by column references an external user but lacked an index,
 * causing slow JOIN and WHERE queries on moderation audit trails.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::MySQL => $connection->execute(
                'CREATE INDEX idx_forum_posts_edited_by ON forum_posts (edited_by)',
            ),
            Driver::SQLite, Driver::PostgreSQL => $connection->execute(
                'CREATE INDEX IF NOT EXISTS idx_forum_posts_edited_by ON forum_posts (edited_by)',
            ),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        match ($driver) {
            Driver::MySQL => $connection->execute(
                'ALTER TABLE forum_posts DROP INDEX idx_forum_posts_edited_by',
            ),
            Driver::SQLite, Driver::PostgreSQL => $connection->execute(
                'DROP INDEX IF EXISTS idx_forum_posts_edited_by',
            ),
        };
    }
};
