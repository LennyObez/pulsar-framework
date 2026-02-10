<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Channel\SmsChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\SmsGatewayInterface;
use Pulsar\Notification\SmsMessage;
use RuntimeException;

#[CoversClass(SmsChannel::class)]
final class SmsChannelTest extends TestCase
{
    #[Test]
    public function sendDispatchesSmsViaGateway(): void
    {
        $gateway = $this->createMock(SmsGatewayInterface::class);
        $gateway->expects(self::once())->method('send');

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-001');
        $notifiable->method('routeNotificationFor')->willReturn(null);

        $notification = $this->createSmsNotification('+1234567890', 'Hello');

        $channel = new SmsChannel($gateway);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendOverridesRecipientFromRouting(): void
    {
        $gateway = $this->createMock(SmsGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('send')
            ->with(self::callback(static fn(SmsMessage $msg): bool => $msg->to === '+9876543210'));

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-002');
        $notifiable->method('routeNotificationFor')->willReturn('+9876543210');

        $notification = $this->createSmsNotification('+1234567890', 'Hi');

        $channel = new SmsChannel($gateway);
        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsOnGatewayFailure(): void
    {
        $gateway = $this->createStub(SmsGatewayInterface::class);
        $gateway->method('send')->willThrowException(new RuntimeException('Gateway down'));

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('getNotifiableId')->willReturn('user-003');
        $notifiable->method('routeNotificationFor')->willReturn(null);

        $notification = $this->createSmsNotification('+111', 'Fail');

        $channel = new SmsChannel($gateway);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('delivery failed');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function nameReturnsSms(): void
    {
        $gateway = $this->createStub(SmsGatewayInterface::class);
        $channel = new SmsChannel($gateway);

        self::assertSame('sms', $channel->name());
    }

    private function createSmsNotification(string $to, string $body): Notification
    {
        return new class ($to, $body) extends Notification {
            public function __construct(
                private readonly string $to,
                private readonly string $body,
            ) {}

            public function via(NotifiableInterface $notifiable): array
            {
                return ['sms'];
            }

            public function toSms(NotifiableInterface $notifiable): SmsMessage
            {
                return new SmsMessage(to: $this->to, body: $this->body);
            }
        };
    }
}
