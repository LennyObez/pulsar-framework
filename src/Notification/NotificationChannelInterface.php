<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * Contract for notification delivery channels.
 * @api
 */
#[Api(since: '1.0.0')]
interface NotificationChannelInterface
{
    /**
     * Deliver a notification to the given notifiable via this channel.
     */
    public function send(NotifiableInterface $notifiable, Notification $notification): void;

    /**
     * Get the channel name (matches NotificationChannelType values).
     */
    public function name(): string;
}
