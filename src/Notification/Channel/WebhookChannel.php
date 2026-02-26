<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Pulsar\Api\Internal;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Pulsar\Notification\NotificationHttpClientInterface;
use Throwable;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Delivers notifications to arbitrary webhook endpoints.
 */
#[Internal]
final readonly class WebhookChannel implements NotificationChannelInterface
{
    public function __construct(
        private NotificationHttpClientInterface $httpClient,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $payload = $notification->toWebhook($notifiable);

        $url = $payload->url;

        if ($url === '') {
            $route = $notifiable->routeNotificationFor($this->name());

            if (!is_string($route) || $route === '') {
                throw NotificationException::channelNotAvailable(
                    $this->name(),
                    'No webhook URL configured for notifiable ' . $notifiable->getNotifiableId(),
                );
            }

            $url = $route;
        }

        $headers = $payload->headers;

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $statusCode = $this->httpClient->request(
                $payload->method,
                $url,
                $headers,
                json_encode($payload->data, JSON_THROW_ON_ERROR),
            );

            if ($statusCode >= 400) {
                throw NotificationException::deliveryFailed(
                    $this->name(),
                    $notifiable->getNotifiableId(),
                );
            }
        } catch (NotificationException $e) {
            throw $e;
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
        return NotificationChannelType::Webhook->value;
    }
}
