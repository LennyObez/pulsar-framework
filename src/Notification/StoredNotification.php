<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * DTO representing a persisted notification record from the database.
 */
#[Api(since: '1.0.0')]
readonly class StoredNotification
{
    /**
     * @param string $id Notification unique identifier
     * @param string $notifiableId Owner of the notification
     * @param string $type Notification class name
     * @param array<string, mixed> $data Notification payload
     * @param int|null $readAt Unix timestamp when read, null if unread
     * @param int $createdAt Unix timestamp when created
     */
    public function __construct(
        public string $id,
        public string $notifiableId,
        public string $type,
        public array $data,
        public ?int $readAt,
        public int $createdAt,
    ) {}

    /**
     * Whether this notification has been read.
     */
    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /**
     * Whether this notification is unread.
     */
    public function isUnread(): bool
    {
        return $this->readAt === null;
    }
}
