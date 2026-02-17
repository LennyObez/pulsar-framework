<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Notification\Channel\LogChannel;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;

#[CoversClass(LogChannel::class)]
final class LogChannelTest extends TestCase
{
    #[Test]
    public function it_logs_notification(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Notification dispatched',
                self::callback(function (array $context): bool {
                    return $context['notifiable_id'] === 'user-1'
                        && $context['channels'] === ['log'];
                }),
            );

        $channel = new LogChannel($logger);

        $notifiable = $this->createNotifiable('user-1');
        $notification = $this->createNotification();

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function it_reports_log_channel_name(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $channel = new LogChannel($logger);

        self::assertSame(NotificationChannelType::Log->value, $channel->name());
    }

    private function createNotifiable(string $id): NotifiableInterface
    {
        return new class ($id) implements NotifiableInterface {
            public function __construct(private readonly string $id) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return null;
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

    private function createNotification(): Notification
    {
        return new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['log'];
            }
        };
    }
}
