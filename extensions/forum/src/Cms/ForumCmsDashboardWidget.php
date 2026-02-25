<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Cms;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;

use function date;
use function time;

/**
 * CMS dashboard widget displaying forum overview metrics.
 *
 * Uses direct COUNT queries via ConnectionInterface for efficient
 * aggregate computation without loading entity collections.
 */
#[Api(since: '1.0.0')]
final readonly class ForumCmsDashboardWidget implements DashboardWidgetInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'forum_overview';
    }

    #[Override]
    public function getData(): array
    {
        return [
            'thread_count' => $this->countThreads(),
            'post_count' => $this->countPosts(),
            'pending_reports' => $this->countPendingReports(),
            'active_users_24h' => $this->countActiveUsers(),
        ];
    }

    #[Override]
    public function getTemplate(): string
    {
        return 'forum/dashboard-widget';
    }

    private function countThreads(): int
    {
        $result = $this->connection->query(
            'SELECT COUNT(*) AS cnt FROM forum_threads WHERE deleted_at IS NULL',
        );

        return $result->first()?->getInt('cnt') ?? 0;
    }

    private function countPosts(): int
    {
        $result = $this->connection->query(
            'SELECT COUNT(*) AS cnt FROM forum_posts WHERE deleted_at IS NULL',
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
