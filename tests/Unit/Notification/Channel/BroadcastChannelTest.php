<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Channel\BroadcastChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\WebSocket\BroadcastManagerInterface;
use RuntimeException;

#[CoversClass(BroadcastChannel::class)]
final class BroadcastChannelTest extends TestCase
{
    #[Test]
    public function sendsToNotifiableChannel(): void
    {
        $broadcast = $this->createMock(BroadcastManagerInterface::class);
        $broadcast->expects(self::once())->method('broadcast')
            ->with('private-user.42', 'notification', ['msg' => 'hello']);

        $channel = new BroadcastChannel($broadcast);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('private-user.42');
        $notifiable->method('getNotifiableId')->willReturn('42');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['broadcast'];
            }

            public function toBroadcast(NotifiableInterface $notifiable): array
            {
                return ['msg' => 'hello'];
            }
        };

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function fallsBackToDefaultChannelWhenRouteEmpty(): void
    {
        $broadcast = $this->createMock(BroadcastManagerInterface::class);
        $broadcast->expects(self::once())->method('broadcast')
            ->with('private-notifications.user-99', 'notification', self::anything());

        $channel = new BroadcastChannel($broadcast);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('');
        $notifiable->method('getNotifiableId')->willReturn('user-99');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['broadcast'];
            }

            public function toBroadcast(NotifiableInterface $notifiable): array
            {
                return ['type' => 'test'];
            }
        };

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function wrapsTransportErrorInNotificationException(): void
    {
        $broadcast = $this->createStub(BroadcastManagerInterface::class);
        $broadcast->method('broadcast')->willThrowException(new RuntimeException('transport failure'));

        $channel = new BroadcastChannel($broadcast);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('test-channel');
        $notifiable->method('getNotifiableId')->willReturn('123');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['broadcast'];
            }

            public function toBroadcast(NotifiableInterface $notifiable): array
            {
                return [];
            }
        };

        $this->expectException(NotificationException::class);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function nameReturnsBroadcast(): void
    {
        $broadcast = $this->createStub(BroadcastManagerInterface::class);
        $channel = new BroadcastChannel($broadcast);

        self::assertSame('broadcast', $channel->name());
    }
}
