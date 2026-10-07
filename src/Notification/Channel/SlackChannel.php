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
use Pulsar\Security\Validation\UrlSafetyValidator;
use Throwable;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Delivers notifications to Slack via incoming webhooks.
 */
#[Internal]
final readonly class SlackChannel implements NotificationChannelInterface
{
    public function __construct(
        private NotificationHttpClientInterface $httpClient,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $message = $notification->toSlack($notifiable);

        $webhookUrl = $notifiable->routeNotificationFor($this->name());

        if (!is_string($webhookUrl) || $webhookUrl === '') {
            throw NotificationException::channelNotAvailable(
                $this->name(),
                'No Slack webhook URL configured for notifiable ' . $notifiable->getNotifiableId(),
            );
        }

        $validation = UrlSafetyValidator::validate($webhookUrl);

        if (!$validation->safe) {
            throw NotificationException::channelNotAvailable(
                $this->name(),
                $validation->reason,
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = ['channel' => $message->channel, 'text' => $message->text];

        if ($message->blocks !== []) {
            $payload['blocks'] = $message->blocks;
        }

        if ($message->username !== null) {
            $payload['username'] = $message->username;
        }

        if ($message->iconEmoji !== null) {
            $payload['icon_emoji'] = $message->iconEmoji;
        }

        try {
            $statusCode = $this->httpClient->request(
                'POST',
                $webhookUrl,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR),
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
        return NotificationChannelType::Slack->value;
    }
}
