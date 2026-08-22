<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Notification;

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
final class WebhookIntegrationTest extends TestCase
{
    #[Test]
    public function it_delivers_webhook_notification_to_public_url(): void
    {
        $httpClient = new RecordingHttpClient(200);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-42', 'https://example.com/webhook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'https://hooks.example.com/events',
                    data: ['event' => 'user.created', 'user_id' => $notifiable->getNotifiableId()],
                    headers: ['X-Webhook-Secret' => 'secret-token'],
                );
            }
        };

        $channel->send($notifiable, $notification);

        self::assertSame('POST', $httpClient->lastMethod);
        self::assertSame('https://hooks.example.com/events', $httpClient->lastUrl);
        self::assertSame('secret-token', $httpClient->lastHeaders['X-Webhook-Secret'] ?? null);
        self::assertSame('application/json', $httpClient->lastHeaders['Content-Type'] ?? null);
        self::assertNotNull($httpClient->lastBody);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($httpClient->lastBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('user.created', $payload['event']);
        self::assertSame('user-42', $payload['user_id']);
    }

    #[Test]
    public function it_uses_notifiable_route_when_payload_url_is_empty(): void
    {
        $httpClient = new RecordingHttpClient(200);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-99', 'https://fallback.example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(url: '', data: ['ping' => true]);
            }
        };

        $channel->send($notifiable, $notification);

        self::assertSame('https://fallback.example.com/hook', $httpClient->lastUrl);
    }

    #[Test]
    public function it_rejects_private_ip_webhook_url(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-1', 'https://example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'http://192.168.1.1/internal',
                    data: ['test' => true],
                );
            }
        };

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('private IPv4');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_rejects_cloud_metadata_endpoint(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-1', 'https://example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'http://169.254.169.254/latest/meta-data/',
                    data: [],
                );
            }
        };

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('blocked address');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_allows_private_ip_when_configured(): void
    {
        $httpClient = new RecordingHttpClient(200);

        $channel = new WebhookChannel($httpClient, allowPrivateNetworks: true);

        $notifiable = $this->createNotifiable('svc-1', 'https://example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'http://10.0.0.5:8080/internal-hook',
                    data: ['internal' => true],
                );
            }
        };

        $channel->send($notifiable, $notification);

        self::assertSame('http://10.0.0.5:8080/internal-hook', $httpClient->lastUrl);
    }

    #[Test]
    public function it_throws_on_http_error_status(): void
    {
        $httpClient = new RecordingHttpClient(500);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-10', 'https://example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'https://hooks.example.com/fail',
                    data: ['test' => true],
                );
            }
        };

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_throws_when_no_webhook_url_available(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-no-url', '');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(url: '', data: []);
            }
        };

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('No webhook URL configured');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_reports_channel_name_as_webhook(): void
    {
        $httpClient = $this->createStub(NotificationHttpClientInterface::class);

        $channel = new WebhookChannel($httpClient);

        self::assertSame('webhook', $channel->name());
    }

    #[Test]
    public function it_wraps_transport_exceptions(): void
    {
        $httpClient = new class implements NotificationHttpClientInterface {
            public function request(string $method, string $url, array $headers, string $body): int
            {
                throw new RuntimeException('Connection timed out');
            }
        };

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-err', 'https://example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'https://hooks.example.com/timeout',
                    data: ['test' => true],
                );
            }
        };

        $this->expectException(NotificationException::class);

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_preserves_custom_content_type_header(): void
    {
        $httpClient = new RecordingHttpClient(200);

        $channel = new WebhookChannel($httpClient);

        $notifiable = $this->createNotifiable('user-xml', 'https://example.com/hook');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['webhook'];
            }

            public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
            {
                return new WebhookPayload(
                    url: 'https://hooks.example.com/xml',
                    data: ['test' => true],
                    headers: ['Content-Type' => 'application/xml'],
                );
            }
        };

        $channel->send($notifiable, $notification);

        self::assertSame('application/xml', $httpClient->lastHeaders['Content-Type'] ?? null);
    }

    private function createNotifiable(string $id, string $webhookUrl): NotifiableInterface
    {
        return new class ($id, $webhookUrl) implements NotifiableInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $webhookUrl,
            ) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return $this->webhookUrl;
            }

            public function getNotifiableId(): string
            {
                return $this->id;
            }

            public function preferredLocale(): ?string
            {
                return null;
            }
        };
    }
}

/**
 * Recording HTTP client that captures the last request for assertions.
 *
 * @internal Test helper only
 */
final class RecordingHttpClient implements NotificationHttpClientInterface
{
    public ?string $lastMethod = null;
    public ?string $lastUrl = null;
    /** @var array<string, string> */
    public array $lastHeaders = [];
    public ?string $lastBody = null;

    public function __construct(
        private readonly int $statusCode,
    ) {}

    public function request(string $method, string $url, array $headers, string $body): int
    {
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;

        return $this->statusCode;
    }
}
