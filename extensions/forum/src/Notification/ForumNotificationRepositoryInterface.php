<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for persisted forum notifications.
 */
#[Api(since: '1.0.0')]
interface ForumNotificationRepositoryInterface
{
    public function findById(string $id): ?ForumNotification;

    /**
     * Paginated notifications for a user, ordered by creation date descending.
     *
     * @return PaginationResult<ForumNotification>
     */
    public function findByUser(string $userId, int $page = 1, int $perPage = 20): PaginationResult;

    /**
     * Count unread notifications for a user.
     */
    public function countUnread(string $userId): int;

    /**
     * Mark a single notification as read.
     */
    public function markRead(string $id): void;

    /**
     * Mark all notifications for a user as read.
     */
    public function markAllRead(string $userId): void;

    public function save(ForumNotification $notification): void;

    public function delete(ForumNotification $notification): void;
}
