<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Pulsar\Api\Internal;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Pulsar\WebSocket\BroadcastManagerInterface;
use Throwable;

use function is_string;

/**
 * Delivers notifications via WebSocket broadcast to a user-specific channel.
 *
 * The channel name is derived from the notifiable's routeNotificationFor('broadcast')
 * method, which should return a channel name string (e.g., "private-user.42").
 */
#[Internal]
final readonly class BroadcastChannel implements NotificationChannelInterface
{
    public function __construct(
        private BroadcastManagerInterface $broadcastManager,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $data = $notification->toBroadcast($notifiable);
        /** @var mixed $channel */
        $channel = $notifiable->routeNotificationFor($this->name());

        if (!is_string($channel) || $channel === '') {
            $channel = 'private-notifications.' . $notifiable->getNotifiableId();
        }

        try {
            $this->broadcastManager->broadcast(
                $channel,
                'notification',
                $data,
            );
        } catch (Throwable $e) {
            throw NotificationException::deliveryFailed(
                $this->name(),
                $notifiable->getNotifiableId(),
                $e,
            );
        }
    }

    public function name(): string
    {
        return 'broadcast';
    }
}
