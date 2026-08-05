<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        match ($connection->driver()) {
            Driver::SQLite => $this->upSqlite($connection),
            Driver::MySQL => $this->upMysql($connection),
            Driver::PostgreSQL => $this->upPostgresql($connection),
        };
    }

    /**
     * MySQL accepts no `IF EXISTS` on `DROP INDEX`, so the branch written for it here was
     * invalid syntax rather than a portability nicety — on a path that runs rarely enough
     * for nobody to notice. The dialect knows each engine's spelling, including that
     * MySQL needs the owning table named.
     */
    public function down(ConnectionInterface $connection): void
    {
        $dialect = $connection->dialect();

        // Map each index to its correct table
        $indexTableMap = [
            'idx_threads_category_pinned' => 'forum_threads',
            'idx_threads_author' => 'forum_threads',
            'idx_threads_tenant_recent' => 'forum_threads',
            'idx_threads_slug_tenant' => 'forum_threads',
            'idx_posts_thread_created' => 'forum_posts',
            'idx_posts_author' => 'forum_posts',
            'idx_thread_votes_user_thread' => 'forum_thread_votes',
            'idx_post_votes_user_post' => 'forum_post_votes',
            'idx_profiles_user_tenant' => 'forum_profiles',
            'idx_profiles_reputation' => 'forum_profiles',
            'idx_post_reports_status' => 'forum_post_reports',
            'idx_thread_reports_status' => 'forum_thread_reports',
            'idx_subscriptions_thread' => 'forum_thread_subscriptions',
            'idx_subscriptions_user_tenant' => 'forum_thread_subscriptions',
            'idx_badges_user_tenant' => 'forum_user_badges',
        ];

        foreach ($indexTableMap as $index => $table) {
            $connection->execute($dialect->compileDropIndex($index, $table));
        }
    }

    private function upSqlite(ConnectionInterface $connection): void
    {
        // Thread indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_category_pinned ON forum_threads (category_id, is_pinned, last_activity_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_author ON forum_threads (author_id) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_tenant_recent ON forum_threads (tenant_id, last_activity_at) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_slug_tenant ON forum_threads (slug, tenant_id) WHERE deleted_at IS NULL');

        // Post indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_posts_thread_created ON forum_posts (thread_id, created_at) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_posts_author ON forum_posts (author_id) WHERE deleted_at IS NULL');

        // Vote indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_thread_votes_user_thread ON forum_thread_votes (user_id, thread_id, tenant_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_post_votes_user_post ON forum_post_votes (user_id, post_id, tenant_id)');

        // Profile indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_profiles_user_tenant ON forum_profiles (user_id, tenant_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_profiles_reputation ON forum_profiles (tenant_id, reputation_score)');

        // Report indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_post_reports_status ON forum_post_reports (status, tenant_id, created_at)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_thread_reports_status ON forum_thread_reports (status, tenant_id, created_at)');

        // Subscription indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_subscriptions_thread ON forum_thread_subscriptions (thread_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_tenant ON forum_thread_subscriptions (user_id, tenant_id)');

        // Badge indexes
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_badges_user_tenant ON forum_user_badges (user_id, tenant_id)');
    }

    private function upMysql(ConnectionInterface $connection): void
    {
        $this->createIndexIfNotExists($connection, 'idx_threads_category_pinned', 'forum_threads', '(category_id, is_pinned, last_activity_at)');
        $this->createIndexIfNotExists($connection, 'idx_threads_author', 'forum_threads', '(author_id)');
        $this->createIndexIfNotExists($connection, 'idx_threads_tenant_recent', 'forum_threads', '(tenant_id, last_activity_at)');
        $this->createIndexIfNotExists($connection, 'idx_threads_slug_tenant', 'forum_threads', '(slug, tenant_id)');

        $this->createIndexIfNotExists($connection, 'idx_posts_thread_created', 'forum_posts', '(thread_id, created_at)');
        $this->createIndexIfNotExists($connection, 'idx_posts_author', 'forum_posts', '(author_id)');

        $this->createIndexIfNotExists($connection, 'idx_thread_votes_user_thread', 'forum_thread_votes', '(user_id, thread_id, tenant_id)');
        $this->createIndexIfNotExists($connection, 'idx_post_votes_user_post', 'forum_post_votes', '(user_id, post_id, tenant_id)');

        $this->createIndexIfNotExists($connection, 'idx_profiles_user_tenant', 'forum_profiles', '(user_id, tenant_id)');
        $this->createIndexIfNotExists($connection, 'idx_profiles_reputation', 'forum_profiles', '(tenant_id, reputation_score)');

        $this->createIndexIfNotExists($connection, 'idx_post_reports_status', 'forum_post_reports', '(status, tenant_id, created_at)');
        $this->createIndexIfNotExists($connection, 'idx_thread_reports_status', 'forum_thread_reports', '(status, tenant_id, created_at)');

        $this->createIndexIfNotExists($connection, 'idx_subscriptions_thread', 'forum_thread_subscriptions', '(thread_id)');
        $this->createIndexIfNotExists($connection, 'idx_subscriptions_user_tenant', 'forum_thread_subscriptions', '(user_id, tenant_id)');

        $this->createIndexIfNotExists($connection, 'idx_badges_user_tenant', 'forum_user_badges', '(user_id, tenant_id)');
    }

    private function upPostgresql(ConnectionInterface $connection): void
    {
        // Partial indexes for common queries
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_category_pinned ON forum_threads (category_id, is_pinned DESC, last_activity_at DESC NULLS LAST) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_author ON forum_threads (author_id, created_at DESC) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_tenant_recent ON forum_threads (tenant_id, last_activity_at DESC NULLS LAST) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_threads_slug_tenant ON forum_threads (slug, tenant_id) WHERE deleted_at IS NULL');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_posts_thread_created ON forum_posts (thread_id, created_at ASC) WHERE deleted_at IS NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_posts_author ON forum_posts (author_id, created_at DESC) WHERE deleted_at IS NULL');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_thread_votes_user_thread ON forum_thread_votes (user_id, thread_id, tenant_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_post_votes_user_post ON forum_post_votes (user_id, post_id, tenant_id)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_profiles_user_tenant ON forum_profiles (user_id, tenant_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_profiles_reputation ON forum_profiles (tenant_id, reputation_score DESC)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_post_reports_status ON forum_post_reports (status, tenant_id, created_at ASC)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_thread_reports_status ON forum_thread_reports (status, tenant_id, created_at ASC)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_subscriptions_thread ON forum_thread_subscriptions (thread_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_tenant ON forum_thread_subscriptions (user_id, tenant_id)');

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_badges_user_tenant ON forum_user_badges (user_id, tenant_id)');
    }

    private function createIndexIfNotExists(
        ConnectionInterface $connection,
        string $indexName,
        string $table,
        string $columns,
    ): void {
        $result = $connection->query(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = :name',
            ['name' => $indexName],
        );

        if (($result->first()?->getInt('cnt') ?? 0) === 0) {
            $connection->execute("CREATE INDEX $indexName ON $table $columns");
        }
    }
};
