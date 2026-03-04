<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Pulsar\Api\Internal;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Pulsar\Notification\NotificationHttpClientInterface;
use Throwable;

use function is_string;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Delivers push notifications via Firebase Cloud Messaging (FCM) HTTP v1 API.
 *
 * Requires an OAuth2 Bearer token and FCM project ID. The notifiable must
 * provide a device token via routeNotificationFor('push').
 */
#[Internal]
final readonly class PushChannel implements NotificationChannelInterface
{
    private const string FCM_V1_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    public function __construct(
        private NotificationHttpClientInterface $httpClient,
        private string $projectId,
        private string $oauthToken,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $message = $notification->toPush($notifiable);

        $deviceToken = $notifiable->routeNotificationFor($this->name());

        if (!is_string($deviceToken) || $deviceToken === '') {
            throw NotificationException::channelNotAvailable(
                $this->name(),
                'No push device token configured for notifiable ' . $notifiable->getNotifiableId(),
            );
        }

        /** @var array{title: string, body: string, image?: string} $notification */
        $notification = [
            'title' => $message->title,
            'body' => $message->body,
        ];

        if ($message->imageUrl !== null) {
            $notification['image'] = $message->imageUrl;
        }

        /** @var array{token: string, notification: array<string, string>, data?: array<string, string>, android?: array<string, mixed>, webpush?: array<string, mixed>} $fcmMessage */
        $fcmMessage = [
            'token' => $deviceToken,
            'notification' => $notification,
        ];

        if ($message->data !== []) {
            $fcmMessage['data'] = $message->data;
        }

        if ($message->clickAction !== null) {
            $fcmMessage['android'] = [
                'notification' => ['click_action' => $message->clickAction],
            ];
            $fcmMessage['webpush'] = [
                'fcm_options' => ['link' => $message->clickAction],
            ];
        }

        $payload = ['message' => $fcmMessage];
        $endpoint = sprintf(self::FCM_V1_ENDPOINT, $this->projectId);

        try {
            $statusCode = $this->httpClient->request(
                'POST',
                $endpoint,
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                ],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
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
        return 'push';
    }
}
