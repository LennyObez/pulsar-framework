<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;
use Pulsar\Notification\Event\NotificationFailed;

/**
 * Application notification manager: public API for sending notifications.
 * @api
 */
#[Api(since: '1.0.0')]
interface NotificationManagerInterface
{
    /**
     * Send a notification to the given notifiable.
     *
     * Resolves channels from the notification's via() method and dispatches to each.
     *
     * Delivery is fire-and-forget: per-channel failures are caught, audit-logged and
     * surfaced via {@see NotificationFailed} events rather than thrown. Subscribe to
     * that event to detect delivery failures.
     */
    public function send(NotifiableInterface $notifiable, Notification $notification): void;

    /**
     * Send a notification immediately, bypassing any queue.
     *
     * Delivery is fire-and-forget: per-channel failures are caught, audit-logged and
     * surfaced via {@see NotificationFailed} events rather than thrown. Subscribe to
     * that event to detect delivery failures.
     *
     * @param list<string>|null $channels Specific channels to send through (null = use notification's via())
     */
    public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void;
}
