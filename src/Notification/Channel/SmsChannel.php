<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Pulsar\Api\Internal;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Pulsar\Notification\SmsGatewayInterface;
use Pulsar\Notification\SmsMessage;
use Throwable;

use function is_string;

/**
 * Delivers notifications via SMS through a gateway adapter.
 */
#[Internal]
final readonly class SmsChannel implements NotificationChannelInterface
{
    public function __construct(
        private SmsGatewayInterface $gateway,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $message = $notification->toSms($notifiable);

        $route = $notifiable->routeNotificationFor($this->name());

        if (is_string($route) && $route !== '') {
            $message = new SmsMessage(
                to: $route,
                body: $message->body,
                from: $message->from,
            );
        }

        try {
            $this->gateway->send($message);
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
        return NotificationChannelType::Sms->value;
    }
}
