<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Support\UuidGenerator;

/**
 * Persisted forum notification entity for the user-facing notification inbox.
 *
 * Each notification records a discrete event that a user should be informed
 * about, with optional structured data for rich rendering.
 */
#[Api(since: '1.0.0')]
final readonly class ForumNotification
{
    /**
     * @param string $id UUIDv7
     * @param string $userId UUIDv7 FK auth_users — the recipient
     * @param string $type Notification type identifier (e.g. 'thread_reply')
     * @param string $title Human-readable title
     * @param string $body Human-readable description
     * @param string $url Deep-link URL for the notification target
     * @param bool $isRead Whether the user has marked this as read
     * @param array<string, mixed> $data Additional structured metadata
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $type,
        public string $title,
        public string $body,
        public string $url,
        public bool $isRead,
        public array $data,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new unread notification.
     *
     * @param array<string, mixed> $data
     */
    public static function create(
        string $userId,
        string $type,
        string $title,
        string $body,
        string $url = '',
        array $data = [],
    ): self {
        return new self(
            id: UuidGenerator::v7(),
            userId: $userId,
            type: $type,
            title: $title,
            body: $body,
            url: $url,
            isRead: false,
            data: $data,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Mark this notification as read.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function markRead(): self
    {
        return clone($this, [
            'isRead' => true,
        ]);
    }
}
