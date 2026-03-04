<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Channel\PushChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationHttpClientInterface;
use Pulsar\Notification\PushMessage;
use RuntimeException;

use function assert;
use function is_string;

#[CoversClass(PushChannel::class)]
final class PushChannelTest extends TestCase
{
    #[Test]
    public function sendsToFcmV1Endpoint(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, string $body): int {
                self::assertSame('POST', $method);
                self::assertSame(
                    'https://fcm.googleapis.com/v1/projects/my-project/messages:send',
                    $url,
                );
                self::assertSame('Bearer oauth-token-123', $headers['Authorization']);

                /** @var array{message: array{token: string, notification: array{title: string, body: string}}} $payload */
                $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('message', $payload);
                self::assertSame('device-token-123', $payload['message']['token']);
                self::assertSame('Test Title', $payload['message']['notification']['title']);
                self::assertSame('Test Body', $payload['message']['notification']['body']);

                return 200;
            });

        $channel = new PushChannel($httpClient, 'my-project', 'oauth-token-123');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('device-token-123');
        $notifiable->method('getNotifiableId')->willReturn('user-1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('Test Title', 'Test Body');
            }
        };

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function throwsWhenNoDeviceToken(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $channel = new PushChannel($httpClient, 'proj', 'token');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('');
        $notifiable->method('getNotifiableId')->willReturn('user-1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('T', 'B');
            }
        };

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('No push device token');
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function throwsOnHttpError(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $httpClient->method('request')->willReturn(401);

        $channel = new PushChannel($httpClient, 'proj', 'token');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('token');
        $notifiable->method('getNotifiableId')->willReturn('u1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('T', 'B');
            }
        };

        $this->expectException(NotificationException::class);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function wrapsTransportExceptionInNotificationException(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('network'));

        $channel = new PushChannel($httpClient, 'proj', 'token');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('token');
        $notifiable->method('getNotifiableId')->willReturn('u1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('T', 'B');
            }
        };

        $this->expectException(NotificationException::class);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function includesDataAndImageInPayload(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, string $body): int {
                /** @var array{message: array{data: array<string, string>, notification: array{image: string}, android: array{notification: array{click_action: string}}, webpush: array{fcm_options: array{link: string}}}} $payload */
                $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                $msg = $payload['message'];
                self::assertSame(['key' => 'val'], $msg['data']);
                self::assertSame('https://img.test/pic.jpg', $msg['notification']['image']);
                self::assertSame('OPEN_ORDERS', $msg['android']['notification']['click_action']);
                self::assertSame('OPEN_ORDERS', $msg['webpush']['fcm_options']['link']);

                return 200;
            });

        $channel = new PushChannel($httpClient, 'proj', 'token');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('device-token');
        $notifiable->method('getNotifiableId')->willReturn('u1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('T', 'B', ['key' => 'val'], 'https://img.test/pic.jpg', 'OPEN_ORDERS');
            }
        };

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function nameReturnsPush(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $channel = new PushChannel($httpClient, 'proj', 'token');

        self::assertSame('push', $channel->name());
    }

    #[Test]
    public function usesOAuth2BearerTokenNotLegacyServerKey(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers): int {
                $auth = $headers['Authorization'] ?? '';
                assert(is_string($auth));
                self::assertStringStartsWith('Bearer ', $auth);
                self::assertStringNotContainsString('key=', $auth);

                return 200;
            });

        $channel = new PushChannel($httpClient, 'my-proj', 'my-oauth-token');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('device-token');
        $notifiable->method('getNotifiableId')->willReturn('u1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('T', 'B');
            }
        };

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function endpointContainsProjectId(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')
            ->willReturnCallback(function (string $method, string $url): int {
                self::assertStringContainsString('/projects/custom-proj-id/', $url);

                return 200;
            });

        $channel = new PushChannel($httpClient, 'custom-proj-id', 'token');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('device-token');
        $notifiable->method('getNotifiableId')->willReturn('u1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['push'];
            }

            public function toPush(NotifiableInterface $notifiable): PushMessage
            {
                return new PushMessage('T', 'B');
            }
        };

        $channel->send($notifiable, $notification);
    }
}
