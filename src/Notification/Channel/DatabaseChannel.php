<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Pulsar\Api\Internal;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Notification\DatabaseNotificationStoreInterface;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Throwable;

/**
 * Stores notifications in a persistent database.
 */
#[Internal]
final readonly class DatabaseChannel implements NotificationChannelInterface
{
    public function __construct(
        private DatabaseNotificationStoreInterface $store,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $data = $notification->toDatabase($notifiable);

        try {
            $this->store->store(
                $notifiable->getNotifiableId(),
                $notification::class,
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
        return NotificationChannelType::Database->value;
    }
}
