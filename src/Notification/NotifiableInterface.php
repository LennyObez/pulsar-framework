<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * Contract for entities that can receive notifications.
 */
#[Api(since: '1.0.0')]
interface NotifiableInterface
{
    /**
     * Get the routing information for the given channel.
     *
     * @return mixed Channel-specific routing (email address, phone number, webhook URL, etc.)
     */
    public function routeNotificationFor(string $channel): mixed;

    /**
     * Get a unique identifier for this notifiable entity.
     */
    public function getNotifiableId(): string;

    /**
     * Get the preferred locale for this notifiable, if any.
     */
    public function preferredLocale(): ?string;
}
