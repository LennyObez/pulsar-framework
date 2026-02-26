<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Repository interface for notification delivery preferences.
 */
#[Api(since: '1.0.0')]
interface NotificationPreferenceRepositoryInterface
{
    /**
     * Find all notification preferences for a user.
     *
     * @return list<NotificationPreference>
     */
    public function findByUser(string $userId): array;

    /**
     * Find a user's preference for a specific event type.
     */
    public function findByUserAndType(string $userId, string $eventType): ?NotificationPreference;

    public function save(NotificationPreference $preference): void;

    public function delete(NotificationPreference $preference): void;
}
