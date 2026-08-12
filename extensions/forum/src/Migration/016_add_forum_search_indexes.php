<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

/**
 * ## Why these two indexes are still written per engine
 *
 * They are not one index spelled three ways. PostgreSQL indexes a `TSVECTOR` column that
 * only it has, with GIN; MySQL indexes the text columns themselves, with FULLTEXT; SQLite
 * has neither and searches with `LIKE`. Different access methods over different columns,
 * and {@see IndexOperations::ensure()} compiles neither — it builds the ordinary index,
 * which over a `TSVECTOR` would answer no `@@` query at all.
 *
 * So the `CREATE` statements stay as they are, and only their guard moves: absence is
 * established by {@see IndexOperations::exists()} rather than by `IF NOT EXISTS`, which
 * MySQL rejects outright on `CREATE INDEX`. Both engines now take the same path to the
 * same question, which is the point — a path only one engine takes is a path only it can
 * break, and this one had been broken on MySQL since it was written.
 */
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
        $indexes = new IndexOperations($connection);

        if (!$indexes->exists('forum_threads', 'idx_threads_search')) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_threads_search
                    ON forum_threads USING GIN (search_vector)
                SQL);
        }

        if (!$indexes->exists('forum_posts', 'idx_posts_search')) {
            $connection->execute(<<<'SQL'
                CREATE INDEX idx_posts_search
                    ON forum_posts USING GIN (search_vector)
                SQL);
        }

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
        $indexes = new IndexOperations($connection);

        if (!$indexes->exists('forum_threads', 'idx_threads_search')) {
            $connection->execute(<<<'SQL'
                CREATE FULLTEXT INDEX idx_threads_search
                    ON forum_threads (title)
                SQL);
        }

        if (!$indexes->exists('forum_posts', 'idx_posts_search')) {
            $connection->execute(<<<'SQL'
                CREATE FULLTEXT INDEX idx_posts_search
                    ON forum_posts (body)
                SQL);
        }
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
        $indexes = new IndexOperations($connection);

        $connection->execute('DROP TRIGGER IF EXISTS trg_posts_search ON forum_posts');
        $connection->execute('DROP FUNCTION IF EXISTS forum_posts_search_trigger()');
        $connection->execute('DROP TRIGGER IF EXISTS trg_threads_search ON forum_threads');
        $connection->execute('DROP FUNCTION IF EXISTS forum_threads_search_trigger()');
        $indexes->ensureAbsent('forum_posts', 'idx_posts_search');
        $indexes->ensureAbsent('forum_threads', 'idx_threads_search');
        $connection->execute('ALTER TABLE forum_posts DROP COLUMN IF EXISTS search_vector');
        $connection->execute('ALTER TABLE forum_threads DROP COLUMN IF EXISTS search_vector');
    }

    /**
     * The branch written for MySQL was the one MySQL could not run: it drops an index
     * through the table that owns it and accepts no `IF EXISTS`, so both statements here
     * were syntax errors and the rollback failed on its first line.
     */
    private function downMysql(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $indexes->ensureAbsent('forum_posts', 'idx_posts_search');
        $indexes->ensureAbsent('forum_threads', 'idx_threads_search');
    }

    /**
     * No-op: nothing was created in upSqlite().
     */
    private function downSqlite(): void
    {
        // No-op
    }
};
