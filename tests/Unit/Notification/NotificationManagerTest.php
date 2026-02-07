<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Config\NotificationConfig;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Notification\Event\NotificationFailed;
use Pulsar\Notification\Event\NotificationSent;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Pulsar\Notification\NotificationManager;

#[CoversClass(NotificationManager::class)]
final class NotificationManagerTest extends TestCase
{
    private NotificationConfig $config;

    protected function setUp(): void
    {
        $this->config = new NotificationConfig(enabled: true);
    }

    #[Test]
    public function it_dispatches_to_channels_from_via(): void
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->expects(self::once())->method('send');
        $channel->method('name')->willReturn('mail');

        $notifiable = $this->createNotifiable('user-1');
        $notification = $this->createNotification(['mail']);

        $manager = new NotificationManager(
            $this->config,
            ['mail' => $channel],
        );

        $manager->send($notifiable, $notification);
    }

    #[Test]
    public function it_uses_default_channels_when_via_is_empty(): void
    {
        $config = new NotificationConfig(
            enabled: true,
            defaultChannels: [NotificationChannelType::Log],
        );

        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->expects(self::once())->method('send');
        $channel->method('name')->willReturn('log');

        $notifiable = $this->createNotifiable('user-2');
        $notification = $this->createNotification([]);

        $manager = new NotificationManager(
            $config,
            ['log' => $channel],
        );

        $manager->send($notifiable, $notification);
    }

    #[Test]
    public function it_emits_failed_event_for_missing_channel(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(NotificationFailed::class));

        $notifiable = $this->createNotifiable('user-3');
        $notification = $this->createNotification(['nonexistent']);

        $manager = new NotificationManager(
            $this->config,
            [],
            eventDispatcher: $dispatcher,
            logger: $this->createStub(LoggerInterface::class),
        );

        $manager->send($notifiable, $notification);
    }

    #[Test]
    public function it_emits_sent_event_on_success(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(NotificationSent::class));

        $channel = $this->createStub(NotificationChannelInterface::class);
        $channel->method('name')->willReturn('mail');

        $notifiable = $this->createNotifiable('user-4');
        $notification = $this->createNotification(['mail']);

        $manager = new NotificationManager(
            $this->config,
            ['mail' => $channel],
            eventDispatcher: $dispatcher,
        );

        $manager->send($notifiable, $notification);
    }

    #[Test]
    public function it_dispatches_to_multiple_channels(): void
    {
        $mailChannel = $this->createMock(NotificationChannelInterface::class);
        $mailChannel->expects(self::once())->method('send');
        $mailChannel->method('name')->willReturn('mail');

        $logChannel = $this->createMock(NotificationChannelInterface::class);
        $logChannel->expects(self::once())->method('send');
        $logChannel->method('name')->willReturn('log');

        $notifiable = $this->createNotifiable('user-5');
        $notification = $this->createNotification(['mail', 'log']);

        $manager = new NotificationManager(
            $this->config,
            ['mail' => $mailChannel, 'log' => $logChannel],
        );

        $manager->send($notifiable, $notification);
    }

    #[Test]
    public function it_does_nothing_when_no_channels_configured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('no channels'));

        $notifiable = $this->createNotifiable('user-6');
        $notification = $this->createNotification([]);

        $manager = new NotificationManager(
            $this->config,
            [],
            logger: $logger,
        );

        $manager->send($notifiable, $notification);
    }

    #[Test]
    public function it_allows_explicit_channel_override_via_send_now(): void
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->expects(self::once())->method('send');
        $channel->method('name')->willReturn('log');

        $notifiable = $this->createNotifiable('user-7');
        // Notification says 'mail' via via(), but sendNow overrides to 'log'
        $notification = $this->createNotification(['mail']);

        $manager = new NotificationManager(
            $this->config,
            ['log' => $channel, 'mail' => $this->createStub(NotificationChannelInterface::class)],
        );

        $manager->sendNow($notifiable, $notification, ['log']);
    }

    private function createNotifiable(string $id): NotifiableInterface
    {
        return new class ($id) implements NotifiableInterface {
            public function __construct(private readonly string $id) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return match ($channel) {
                    'mail' => 'test@example.com',
                    default => null,
                };
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

    /**
     * @param list<string> $channels
     */
    private function createNotification(array $channels): Notification
    {
        return new class ($channels) extends Notification {
            /**
             * @param list<string> $channels
             */
            public function __construct(private readonly array $channels) {}

            public function via(NotifiableInterface $notifiable): array
            {
                return $this->channels;
            }
        };
    }
}
