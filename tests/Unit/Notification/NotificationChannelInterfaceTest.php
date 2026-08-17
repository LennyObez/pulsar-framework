<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;

#[CoversNothing]
final class NotificationChannelInterfaceTest extends TestCase
{
    #[Test]
    public function implementationCanSendAndReturnName(): void
    {
        $channel = new class implements NotificationChannelInterface {
            public int $sendCount = 0;

            public function send(NotifiableInterface $notifiable, Notification $notification): void
            {
                $this->sendCount++;
            }

            public function name(): string
            {
                return 'test-channel';
            }
        };

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['test-channel'];
            }
        };

        $channel->send($notifiable, $notification);

        self::assertSame('test-channel', $channel->name());
        self::assertSame(1, $channel->sendCount);
    }

    #[Test]
    public function nameReturnsConsistentValue(): void
    {
        $channel = new class implements NotificationChannelInterface {
            public function send(NotifiableInterface $notifiable, Notification $notification): void {}

            public function name(): string
            {
                return 'consistent';
            }
        };

        self::assertSame($channel->name(), $channel->name());
    }
}
