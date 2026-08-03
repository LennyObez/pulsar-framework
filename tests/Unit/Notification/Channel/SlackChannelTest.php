<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Channel\SlackChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationHttpClientInterface;
use Pulsar\Notification\SlackMessage;
use RuntimeException;

#[CoversClass(SlackChannel::class)]
final class SlackChannelTest extends TestCase
{
    #[Test]
    public function sendPostsToSlackWebhook(): void
    {
        $httpClient = $this->createMock(NotificationHttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://hooks.slack.com/services/test', self::anything(), self::anything())
            ->willReturn(200);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-001');
        $notifiable->method('routeNotificationFor')->willReturn('https://hooks.slack.com/services/test');

        $notification = $this->createSlackNotification('#general', 'Hello team');

        $channel = new SlackChannel($httpClient);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsWhenNoWebhookUrl(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-002');
        $notifiable->method('routeNotificationFor')->willReturn(null);

        $notification = $this->createSlackNotification('#general', 'Hi');

        $channel = new SlackChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('not available');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsOnHttpError(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $httpClient->method('request')->willReturn(500);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-003');
        $notifiable->method('routeNotificationFor')->willReturn('https://hooks.slack.com/services/test');

        $notification = $this->createSlackNotification('#general', 'Fail');

        $channel = new SlackChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsOnTransportException(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('Network error'));

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-004');
        $notifiable->method('routeNotificationFor')->willReturn('https://hooks.slack.com/services/test');

        $notification = $this->createSlackNotification('#general', 'Fail');

        $channel = new SlackChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendRejectsPrivateNetworkWebhookUrl(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-005');
        $notifiable->method('routeNotificationFor')->willReturn('http://10.0.0.1/webhook');

        $notification = $this->createSlackNotification('#general', 'SSRF test');

        $channel = new SlackChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('private');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendRejectsLocalhostWebhookUrl(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-006');
        $notifiable->method('routeNotificationFor')->willReturn('http://127.0.0.1/webhook');

        $notification = $this->createSlackNotification('#general', 'SSRF test');

        $channel = new SlackChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('private');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendRejectsCloudMetadataWebhookUrl(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-007');
        $notifiable->method('routeNotificationFor')->willReturn('http://169.254.169.254/latest/meta-data');

        $notification = $this->createSlackNotification('#general', 'SSRF test');

        $channel = new SlackChannel($httpClient);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('blocked');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function nameReturnsSlack(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);
        $channel = new SlackChannel($httpClient);

        self::assertSame('slack', $channel->name());
    }

    private function createSlackNotification(string $slackChannel, string $text): Notification
    {
        return new class ($slackChannel, $text) extends Notification {
            public function __construct(
                private readonly string $slackChannel,
                private readonly string $text,
            ) {}

            public function via(NotifiableInterface $notifiable): array
            {
                return ['slack'];
            }

            public function toSlack(NotifiableInterface $notifiable): SlackMessage
            {
                return new SlackMessage(channel: $this->slackChannel, text: $this->text);
            }
        };
    }
}
