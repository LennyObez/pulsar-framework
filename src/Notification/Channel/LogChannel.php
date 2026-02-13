<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;

/**
 * Logs notifications instead of delivering them: useful for testing and development.
 */
#[Internal]
final readonly class LogChannel implements NotificationChannelInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $this->logger->info('Notification dispatched', [
            'notification' => $notification::class,
            'notifiable_id' => $notifiable->getNotifiableId(),
            'channels' => $notification->via($notifiable),
            'locale' => $notification->getLocale() ?? $notifiable->preferredLocale(),
        ]);
    }

    public function name(): string
    {
        return NotificationChannelType::Log->value;
    }
}
