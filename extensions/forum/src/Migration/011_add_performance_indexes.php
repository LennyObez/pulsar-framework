<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexColumn;
use Pulsar\Database\Schema\IndexOperations;

/**
 * Performance indexes for the forum tables.
 *
 * ## Why one list replaced three
 *
 * These fifteen indexes were written once per engine, and the copies had drifted far
 * enough apart to be different indexes. PostgreSQL's were ordered with care —
 * `(category_id, is_pinned DESC, last_activity_at DESC NULLS LAST)`, which is exactly the
 * shape a "hot threads first" listing reads — while SQLite's dropped every direction and
 * MySQL's dropped the soft-delete predicates as well. `idx_threads_author` indexed
 * `(author_id, created_at DESC)` on one engine and `(author_id)` on the other two.
 *
 * None of that was a capability difference. SQLite has had `DESC` since forever and
 * `NULLS LAST` since 3.30, so its copy was not degraded to fit the engine — it was simply
 * the less considered one, and nothing compared them. Stating the intended shape once
 * gives every engine the ordering that was only ever written down for one.
 *
 * What still differs is what the engines genuinely cannot do: MySQL has no partial index
 * and no `NULLS` clause, so it gets the same keys over every row. Wider than asked for,
 * chosen differently by the planner, never a wrong answer — and the dialect is what
 * decides that, not this file.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        foreach ($this->indexes() as [$table, $name, $columns, $where]) {
            $indexes->ensure($table, $name, $columns, where: $where);
        }
    }

    /**
     * MySQL accepts no `IF EXISTS` on `DROP INDEX` and needs the owning table named, which
     * is why this went through the dialect before the rest of the file did — the rollback
     * path runs rarely enough that invalid syntax sat here unnoticed.
     */
    public function down(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        foreach ($this->indexes() as [$table, $name]) {
            $indexes->ensureAbsent($table, $name);
        }
    }

    /**
     * Table, index name, columns, and the predicate that narrows it where the engine can.
     *
     * A method rather than a constant because `IndexColumn` instances cannot appear in
     * one, and the ordering is the part of these definitions worth keeping.
     *
     * @return list<array{string, string, list<string|IndexColumn>, ?string}>
     */
    private function indexes(): array
    {
        $live = 'deleted_at IS NULL';

        return [
            // Thread listings: newest activity first, and a thread with no activity yet
            // sorts last rather than first.
            ['forum_threads', 'idx_threads_category_pinned', [
                'category_id',
                IndexColumn::desc('is_pinned'),
                IndexColumn::desc('last_activity_at', nullsLast: true),
            ], $live],
            ['forum_threads', 'idx_threads_author', [
                'author_id',
                IndexColumn::desc('created_at'),
            ], $live],
            ['forum_threads', 'idx_threads_tenant_recent', [
                'tenant_id',
                IndexColumn::desc('last_activity_at', nullsLast: true),
            ], $live],
            ['forum_threads', 'idx_threads_slug_tenant', ['slug', 'tenant_id'], $live],

            // Posts read oldest-first within a thread, newest-first for an author.
            ['forum_posts', 'idx_posts_thread_created', ['thread_id', 'created_at'], $live],
            ['forum_posts', 'idx_posts_author', [
                'author_id',
                IndexColumn::desc('created_at'),
            ], $live],

            ['forum_thread_votes', 'idx_thread_votes_user_thread', ['user_id', 'thread_id', 'tenant_id'], null],
            ['forum_post_votes', 'idx_post_votes_user_post', ['user_id', 'post_id', 'tenant_id'], null],

            ['forum_profiles', 'idx_profiles_user_tenant', ['user_id', 'tenant_id'], null],
            // Leaderboards read highest reputation first.
            ['forum_profiles', 'idx_profiles_reputation', [
                'tenant_id',
                IndexColumn::desc('reputation_score'),
            ], null],

            // Moderation queues read oldest-first: the longest-waiting report is the one
            // that matters.
            ['forum_post_reports', 'idx_post_reports_status', ['status', 'tenant_id', 'created_at'], null],
            ['forum_thread_reports', 'idx_thread_reports_status', ['status', 'tenant_id', 'created_at'], null],

            ['forum_thread_subscriptions', 'idx_subscriptions_thread', ['thread_id'], null],
            ['forum_thread_subscriptions', 'idx_subscriptions_user_tenant', ['user_id', 'tenant_id'], null],

            ['forum_user_badges', 'idx_badges_user_tenant', ['user_id', 'tenant_id'], null],
        ];
    }
};
