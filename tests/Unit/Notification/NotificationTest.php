<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use BadMethodCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;

#[CoversClass(Notification::class)]
final class NotificationTest extends TestCase
{
    #[Test]
    public function it_returns_via_channels(): void
    {
        $notification = $this->createConcreteNotification(['mail', 'sms']);
        $notifiable = $this->createNotifiable();

        self::assertSame(['mail', 'sms'], $notification->via($notifiable));
    }

    #[Test]
    public function it_sets_and_gets_locale(): void
    {
        $notification = $this->createConcreteNotification([]);

        self::assertNull($notification->getLocale());

        $result = $notification->locale('fr');

        self::assertSame($notification, $result);
        self::assertSame('fr', $notification->getLocale());
    }

    #[Test]
    public function it_clears_locale(): void
    {
        $notification = $this->createConcreteNotification([]);
        $notification->locale('de');
        $notification->locale(null);

        self::assertNull($notification->getLocale());
    }

    #[Test]
    public function it_throws_for_unimplemented_to_mail(): void
    {
        $notification = $this->createConcreteNotification([]);
        $notifiable = $this->createNotifiable();

        $this->expectException(BadMethodCallException::class);
        $notification->toMail($notifiable);
    }

    #[Test]
    public function it_throws_for_unimplemented_to_database(): void
    {
        $notification = $this->createConcreteNotification([]);
        $notifiable = $this->createNotifiable();

        $this->expectException(BadMethodCallException::class);
        $notification->toDatabase($notifiable);
    }

    #[Test]
    public function it_throws_for_unimplemented_to_slack(): void
    {
        $notification = $this->createConcreteNotification([]);
        $notifiable = $this->createNotifiable();

        $this->expectException(BadMethodCallException::class);
        $notification->toSlack($notifiable);
    }

    #[Test]
    public function it_throws_for_unimplemented_to_webhook(): void
    {
        $notification = $this->createConcreteNotification([]);
        $notifiable = $this->createNotifiable();

        $this->expectException(BadMethodCallException::class);
        $notification->toWebhook($notifiable);
    }

    #[Test]
    public function it_throws_for_unimplemented_to_sms(): void
    {
        $notification = $this->createConcreteNotification([]);
        $notifiable = $this->createNotifiable();

        $this->expectException(BadMethodCallException::class);
        $notification->toSms($notifiable);
    }

    /**
     * @param list<string> $channels
     */
    private function createConcreteNotification(array $channels): Notification
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

    private function createNotifiable(): NotifiableInterface
    {
        return new class implements NotifiableInterface {
            public function routeNotificationFor(string $channel): mixed
            {
                return null;
            }

            public function getNotifiableId(): string
            {
                return 'test-user';
            }

            public function preferredLocale(): ?string
            {
                return null;
            }
        };
    }
}
