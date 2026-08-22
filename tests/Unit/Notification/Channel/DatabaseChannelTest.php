<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Channel\DatabaseChannel;
use Pulsar\Notification\DatabaseNotificationStoreInterface;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use RuntimeException;

use function is_string;

#[CoversClass(DatabaseChannel::class)]
final class DatabaseChannelTest extends TestCase
{
    #[Test]
    public function sendStoresNotificationInDatabase(): void
    {
        $store = $this->createMock(DatabaseNotificationStoreInterface::class);
        $store->expects(self::once())
            ->method('store')
            ->with('user-001', self::callback(static fn(mixed $v): bool => is_string($v)), ['type' => 'welcome']);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-001');

        $notification = $this->createNotification(['type' => 'welcome']);

        $channel = new DatabaseChannel($store);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsOnStoreFailure(): void
    {
        $store = $this->createStub(DatabaseNotificationStoreInterface::class);
        $store->method('store')->willThrowException(new RuntimeException('DB down'));

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-002');

        $notification = $this->createNotification([]);

        $channel = new DatabaseChannel($store);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageIsOrContains('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function nameReturnsDatabase(): void
    {
        $store = $this->createStub(DatabaseNotificationStoreInterface::class);
        $channel = new DatabaseChannel($store);

        self::assertSame('database', $channel->name());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createNotification(array $data): Notification
    {
        return new class ($data) extends Notification {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data) {}

            public function via(NotifiableInterface $notifiable): array
            {
                return ['database'];
            }

            /** @return array<string, mixed> */
            public function toDatabase(NotifiableInterface $notifiable): array
            {
                return $this->data;
            }
        };
    }
}
