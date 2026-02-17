<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\NotifiableInterface;

#[CoversClass(NotifiableInterface::class)]
final class NotifiableInterfaceTest extends TestCase
{
    #[Test]
    public function implementationReturnsRoutingInfo(): void
    {
        $notifiable = new class implements NotifiableInterface {
            public bool $hasLocale = true;

            public function routeNotificationFor(string $channel): mixed
            {
                return match ($channel) {
                    'mail' => 'user@example.com',
                    'sms' => '+1234567890',
                    default => null,
                };
            }

            public function getNotifiableId(): string
            {
                return 'user-42';
            }

            public function preferredLocale(): ?string
            {
                return $this->hasLocale ? 'en' : null;
            }
        };

        self::assertSame('user@example.com', $notifiable->routeNotificationFor('mail'));
        self::assertSame('+1234567890', $notifiable->routeNotificationFor('sms'));
        self::assertNull($notifiable->routeNotificationFor('unknown'));
        self::assertSame('user-42', $notifiable->getNotifiableId());
        self::assertSame('en', $notifiable->preferredLocale());

        $notifiable->hasLocale = false;
        self::assertNull($notifiable->preferredLocale());
    }

    #[Test]
    public function implementationCanReturnNullLocale(): void
    {
        $notifiable = new class implements NotifiableInterface {
            public function routeNotificationFor(string $channel): mixed
            {
                return null;
            }

            public function getNotifiableId(): string
            {
                return 'anon-1';
            }

            public function preferredLocale(): ?string
            {
                return null;
            }
        };

        self::assertNull($notifiable->preferredLocale());
        self::assertNull($notifiable->routeNotificationFor('any'));
        self::assertSame('anon-1', $notifiable->getNotifiableId());
    }

    #[Test]
    public function routeNotificationForAcceptsArbitraryChannelNames(): void
    {
        $notifiable = new class implements NotifiableInterface {
            public function routeNotificationFor(string $channel): mixed
            {
                return 'route-for-' . $channel;
            }

            public function getNotifiableId(): string
            {
                return 'id';
            }

            public function preferredLocale(): ?string
            {
                return null;
            }
        };

        self::assertSame('route-for-custom-channel', $notifiable->routeNotificationFor('custom-channel'));
        self::assertSame('route-for-', $notifiable->routeNotificationFor(''));
    }
}
