<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Channel\WebhookChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationHttpClientInterface;
use Pulsar\Notification\WebhookPayload;
use RuntimeException;

#[CoversClass(WebhookChannel::class)]
final class WebhookChannelTest extends TestCase
{
    #[Test]
    public function sendPostsToWebhookUrl(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://example.com/webhook', self::anything(), self::anything())
            ->willReturn(200);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-001');

        $notification = $this->createWebhookNotification('https://example.com/webhook', ['key' => 'value']);

        $channel = new WebhookChannel($httpClient);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendFallsBackToNotifiableRouting(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://route.com/hook', self::anything(), self::anything())
            ->willReturn(200);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-002');
        $notifiable->method('routeNotificationFor')->willReturn('https://route.com/hook');

        $notification = $this->createWebhookNotification('', []);

        $channel = new WebhookChannel($httpClient);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsWhenNoUrlAvailable(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-003');
        $notifiable->method('routeNotificationFor')->willReturn(null);

        $notification = $this->createWebhookNotification('', []);

        $channel = new WebhookChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('not available');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsOnHttpError(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $httpClient->method('request')->willReturn(500);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-004');

        $notification = $this->createWebhookNotification('https://example.com/hook', []);

        $channel = new WebhookChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsOnTransportException(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('timeout'));

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-005');

        $notification = $this->createWebhookNotification('https://example.com/hook', []);

        $channel = new WebhookChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function nameReturnsWebhook(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $channel = new WebhookChannel($httpClient);

        self::assertSame('webhook', $channel->name());
    }

    #[Test]
    public function sendSetsDefaultContentTypeWhenNotProvided(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/hook',
                self::callback(static fn(array $headers): bool => $headers['Content-Type'] === 'application/json'),
                self::anything(),
            )
            ->willReturn(200);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-006');

        $notification = $this->createWebhookNotification('https://example.com/hook', []);

        $channel = new WebhookChannel($httpClient);
        $channel->send($notifiable, $notification);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createWebhookNotification(string $url, array $data): Notification
    {
        return new class ($url, $data) extends Notification {
            /** @param array<string, mixed> $data */
            public function __construct(
                private readonly string $url,
                private readonly array $data,
            ) {}

            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(url: $this->url, data: $this->data);
            }
        };
    }
}
