<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Notification;

use Pulsar\Api\Api;

/**
 * Contract for forum workflow notifications.
 *
 * Each notification knows its type, who should receive it, and how to
 * render a human-readable subject/body for channel-agnostic delivery.
 * @api
 */
#[Api(since: '1.0.0')]
interface ForumNotificationInterface
{
    /**
     * Notification type identifier (e.g. 'thread_reply', 'post_upvoted').
     */
    public function type(): string;

    /**
     * User IDs that should receive this notification.
     *
     * @return list<string>
     */
    public function recipientIds(): array;

    /**
     * Human-readable subject line.
     */
    public function subject(): string;

    /**
     * Human-readable body text.
     */
    public function body(): string;

    /**
     * Additional structured metadata for channel-specific rendering.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
