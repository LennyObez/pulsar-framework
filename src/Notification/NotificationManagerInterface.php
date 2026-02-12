<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;
use Pulsar\Notification\Exception\NotificationException;

/**
 * Application notification manager — public API for sending notifications.
 */
#[Api(since: '1.0.0')]
interface NotificationManagerInterface
{
    /**
     * Send a notification to the given notifiable.
     *
     * Resolves channels from the notification's via() method and dispatches to each.
     *
     * @throws NotificationException If delivery fails
     */
    public function send(NotifiableInterface $notifiable, Notification $notification): void;

    /**
     * Send a notification immediately, bypassing any queue.
     *
     * @param list<string>|null $channels Specific channels to send through (null = use notification's via())
     *
     * @throws NotificationException If delivery fails
     */
    public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void;
}
