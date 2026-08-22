<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationManagerInterface;

#[CoversNothing]
final class NotificationManagerInterfaceTest extends TestCase
{
    #[Test]
    public function sendDispatchesToImplementation(): void
    {
        $manager = new class implements NotificationManagerInterface {
            /** @var list<string> */
            public array $calls = [];

            public function send(NotifiableInterface $notifiable, Notification $notification): void
            {
                $this->calls[] = 'send:' . $notifiable->getNotifiableId();
            }

            public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void
            {
                $this->calls[] = 'sendNow:' . $notifiable->getNotifiableId();
            }
        };

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('u-1');

        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail'];
            }
        };

        $manager->send($notifiable, $notification);

        self::assertSame(['send:u-1'], $manager->calls);
    }

    #[Test]
    public function sendNowAcceptsExplicitChannels(): void
    {
        $manager = new class implements NotificationManagerInterface {
            /** @var list<string>|null */
            public ?array $receivedChannels = null;

            public function send(NotifiableInterface $notifiable, Notification $notification): void {}

            public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void
            {
                $this->receivedChannels = $channels;
            }
        };

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail'];
            }
        };

        $manager->sendNow($notifiable, $notification, ['sms', 'mail']);

        self::assertSame(['sms', 'mail'], $manager->receivedChannels);
    }

    #[Test]
    public function sendNowDefaultsToNullChannels(): void
    {
        $manager = new class implements NotificationManagerInterface {
            /** @var list<string>|null|false */
            public array|null|false $receivedChannels = false;

            public function send(NotifiableInterface $notifiable, Notification $notification): void {}

            public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void
            {
                $this->receivedChannels = $channels;
            }
        };

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notification = new class extends Notification {
            public function via(NotifiableInterface $notifiable): array
            {
                return [];
            }
        };

        $manager->sendNow($notifiable, $notification);

        self::assertNull($manager->receivedChannels);
    }
}
