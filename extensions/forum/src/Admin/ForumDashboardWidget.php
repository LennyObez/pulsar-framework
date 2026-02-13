<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Admin;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;

/**
 * Admin dashboard widget displaying forum overview metrics.
 *
 * Uses direct COUNT queries via ConnectionInterface for efficient
 * aggregate computation without loading entity collections.
 */
#[Api(since: '1.0.0')]
final readonly class ForumDashboardWidget implements WidgetInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'forum_overview';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Overview';
    }

    #[Override]
    public function size(): string
    {
        return 'medium';
    }

    #[Override]
    public function render(): array
    {
        return [
            'thread_count' => $this->countTable('forum_threads'),
            'post_count' => $this->countTable('forum_posts'),
            'pending_reports' => $this->countPendingReports(),
            'active_users_24h' => $this->countActiveUsers(),
        ];
    }

    private function countTable(string $table): int
    {
        $result = $this->connection->query(
            "SELECT COUNT(*) AS cnt FROM $table WHERE deleted_at IS NULL",
        );

        return $result->first()?->getInt('cnt') ?? 0;
    }

    private function countPendingReports(): int
    {
        $threadReports = $this->connection->query(
            'SELECT COUNT(*) AS cnt FROM forum_thread_reports WHERE status = :status',
            ['status' => 'pending'],
        );
        $postReports = $this->connection->query(
            'SELECT COUNT(*) AS cnt FROM forum_post_reports WHERE status = :status',
            ['status' => 'pending'],
        );

        $threadCount = $threadReports->first()?->getInt('cnt') ?? 0;
        $postCount = $postReports->first()?->getInt('cnt') ?? 0;

        return $threadCount + $postCount;
    }

    private function countActiveUsers(): int
    {
        $result = $this->connection->query(
            'SELECT COUNT(DISTINCT author_id) AS cnt FROM forum_posts WHERE created_at >= :since AND deleted_at IS NULL',
            ['since' => date('Y-m-d H:i:s', time() - 86400)],
        );

        return $result->first()?->getInt('cnt') ?? 0;
    }
}
