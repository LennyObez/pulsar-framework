<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Per-user, per-event-type notification delivery preferences.
 *
 * Controls whether a notification type is delivered in-app, via email,
 * and the email batching frequency.
 */
#[Api(since: '1.0.0')]
final readonly class NotificationPreference
{
    /**
     * @param string $userId UUIDv7 FK auth_users
     * @param string $eventType Notification type identifier (e.g. 'thread_reply')
     * @param bool $inApp Whether in-app notifications are enabled
     * @param bool $email Whether email notifications are enabled
     * @param string $emailFrequency Delivery cadence: 'immediate', 'daily', or 'weekly'
     */
    public function __construct(
        public string $userId,
        public string $eventType,
        public bool $inApp,
        public bool $email,
        public string $emailFrequency,
    ) {}

    /**
     * Create a new preference with sensible defaults.
     */
    public static function create(
        string $userId,
        string $eventType,
        bool $inApp = true,
        bool $email = false,
        string $emailFrequency = 'immediate',
    ): self {
        return new self(
            userId: $userId,
            eventType: $eventType,
            inApp: $inApp,
            email: $email,
            emailFrequency: $emailFrequency,
        );
    }

    /**
     * Update delivery settings.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function update(
        bool $inApp,
        bool $email,
        string $emailFrequency,
    ): self {
        return clone($this, [
            'inApp' => $inApp,
            'email' => $email,
            'emailFrequency' => $emailFrequency,
        ]);
    }
}
