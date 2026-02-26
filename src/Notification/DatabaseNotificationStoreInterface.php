<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * Port interface for persisting notifications to a database.
 */
#[Api(since: '1.0.0')]
interface DatabaseNotificationStoreInterface
{
    /**
     * Store a notification record for a notifiable entity.
     *
     * @param string               $notifiableId Unique identifier of the notifiable
     * @param string               $type         Notification class name
     * @param array<string, mixed> $data         Notification payload data
     */
    public function store(string $notifiableId, string $type, array $data): void;

    /**
     * Mark a stored notification as read.
     *
     * @param string $notificationId Unique identifier of the stored notification
     */
    public function markAsRead(string $notificationId): void;
}
