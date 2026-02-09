<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Notification;

use Pulsar\Api\Api;

/**
 * Contract for CMS workflow notifications.
 *
 * Each notification knows its type, who should receive it, and how to
 * render a human-readable subject/body for channel-agnostic delivery.
 */
#[Api(since: '1.0.0')]
interface CmsNotificationInterface
{
    /**
     * Notification type identifier (e.g. 'content_published', 'review_requested').
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
