<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        match ($connection->driver()) {
            Driver::PostgreSQL => $this->upPostgresql($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::SQLite => $this->upSqlite(),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        match ($connection->driver()) {
            Driver::PostgreSQL => $this->downPostgresql($connection),
            Driver::MySQL => $this->downMysql($connection),
            Driver::SQLite => $this->downSqlite(),
        };
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        // Add tsvector columns for full-text search
        $connection->execute(<<<'SQL'
            ALTER TABLE forum_threads
                ADD COLUMN IF NOT EXISTS search_vector TSVECTOR
            SQL);

        $connection->execute(<<<'SQL'
            ALTER TABLE forum_posts
                ADD COLUMN IF NOT EXISTS search_vector TSVECTOR
            SQL);

        // GIN indexes on the tsvector columns
        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_threads_search
                ON forum_threads USING GIN (search_vector)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_posts_search
                ON forum_posts USING GIN (search_vector)
            SQL);

        // Trigger function: update search_vector on forum_threads
        $connection->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION forum_threads_search_trigger()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.search_vector := to_tsvector('english', COALESCE(NEW.title, ''));
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $connection->execute(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_threads_search ON forum_threads
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TRIGGER trg_threads_search
                BEFORE INSERT OR UPDATE OF title ON forum_threads
                FOR EACH ROW
                EXECUTE FUNCTION forum_threads_search_trigger()
            SQL);

        // Trigger function: update search_vector on forum_posts
        $connection->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION forum_posts_search_trigger()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.search_vector := to_tsvector('english', COALESCE(NEW.body, ''));
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $connection->execute(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_posts_search ON forum_posts
            SQL);

        $connection->execute(<<<'SQL'
            CREATE TRIGGER trg_posts_search
                BEFORE INSERT OR UPDATE OF body ON forum_posts
                FOR EACH ROW
                EXECUTE FUNCTION forum_posts_search_trigger()
            SQL);

        // Backfill existing rows
        $connection->execute(<<<'SQL'
            UPDATE forum_threads
            SET search_vector = to_tsvector('english', COALESCE(title, ''))
            WHERE search_vector IS NULL
            SQL);

        $connection->execute(<<<'SQL'
            UPDATE forum_posts
            SET search_vector = to_tsvector('english', COALESCE(body, ''))
            WHERE search_vector IS NULL
            SQL);
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        // MySQL FULLTEXT indexes on the text columns directly
        $connection->execute(<<<'SQL'
            CREATE FULLTEXT INDEX IF NOT EXISTS idx_threads_search
                ON forum_threads (title)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE FULLTEXT INDEX IF NOT EXISTS idx_posts_search
                ON forum_posts (body)
            SQL);
    }

    /**
     * SQLite has no native full-text index support on existing tables.
     * Search falls back to LIKE queries: no schema changes needed.
     */
    private function upSqlite(): void
    {
        // No-op: SQLite uses LIKE fallback for search
    }

    private function downPostgresql(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TRIGGER IF EXISTS trg_posts_search ON forum_posts');
        $connection->execute('DROP FUNCTION IF EXISTS forum_posts_search_trigger()');
        $connection->execute('DROP TRIGGER IF EXISTS trg_threads_search ON forum_threads');
        $connection->execute('DROP FUNCTION IF EXISTS forum_threads_search_trigger()');
        $connection->execute('DROP INDEX IF EXISTS idx_posts_search');
        $connection->execute('DROP INDEX IF EXISTS idx_threads_search');
        $connection->execute('ALTER TABLE forum_posts DROP COLUMN IF EXISTS search_vector');
        $connection->execute('ALTER TABLE forum_threads DROP COLUMN IF EXISTS search_vector');
    }

    private function downMysql(ConnectionInterface $connection): void
    {
        $connection->execute('DROP INDEX IF EXISTS idx_posts_search ON forum_posts');
        $connection->execute('DROP INDEX IF EXISTS idx_threads_search ON forum_threads');
    }

    /**
     * No-op: nothing was created in upSqlite().
     */
    private function downSqlite(): void
    {
        // No-op
    }
};
