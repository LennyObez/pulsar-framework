<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\SnsNotificationChannel;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;

#[CoversClass(SnsNotificationChannel::class)]
final class SnsNotificationChannelTest extends TestCase
{
    #[Test]
    public function nameReturnsSns(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $channel = new SnsNotificationChannel($config);

        self::assertSame('sns', $channel->name());
    }

    #[Test]
    public function sendThrowsWhenNoTargetArn(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $channel = new SnsNotificationChannel($config);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn('');
        $notifiable->method('getNotifiableId')->willReturn('user-1');

        $notification = $this->createStub(Notification::class);

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessageMatches('/No SNS target ARN/');

        $channel->send($notifiable, $notification);
    }

    #[Test]
    public function sendThrowsWhenTargetArnIsNull(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $channel = new SnsNotificationChannel($config);

        $notifiable = $this->createStub(NotifiableInterface::class);
        $notifiable->method('routeNotificationFor')->willReturn(null);
        $notifiable->method('getNotifiableId')->willReturn('user-2');

        $notification = $this->createStub(Notification::class);

        $this->expectException(NotificationException::class);

        $channel->send($notifiable, $notification);
    }
}
