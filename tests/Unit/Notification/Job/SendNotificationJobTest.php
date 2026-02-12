<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Job\SendNotificationJob;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationManagerInterface;
use Pulsar\Queue\JobContext;

#[CoversClass(SendNotificationJob::class)]
final class SendNotificationJobTest extends TestCase
{
    #[Test]
    public function handleSendsNotificationViaManager(): void
    {
        $notifiable = $this->createStub(NotifiableInterface::class);
        $notification = $this->createStub(Notification::class);
        $manager = $this->createMock(NotificationManagerInterface::class);
        $manager->expects(self::once())
            ->method('sendNow')
            ->with($notifiable, $notification, null);

        $job = new SendNotificationJob($notifiable, $notification, $manager);
        $job->handle($this->createStub(JobContext::class));
    }

    #[Test]
    public function handlePassesSpecificChannels(): void
    {
        $notifiable = $this->createStub(NotifiableInterface::class);
        $notification = $this->createStub(Notification::class);
        $manager = $this->createMock(NotificationManagerInterface::class);
        $manager->expects(self::once())
            ->method('sendNow')
            ->with($notifiable, $notification, ['mail', 'sms']);

        $job = new SendNotificationJob($notifiable, $notification, $manager, channels: ['mail', 'sms']);
        $job->handle($this->createStub(JobContext::class));
    }

    #[Test]
    public function defaultQueueIsNotifications(): void
    {
        $job = new SendNotificationJob(
            $this->createStub(NotifiableInterface::class),
            $this->createStub(Notification::class),
            $this->createStub(NotificationManagerInterface::class),
        );

        self::assertSame('notifications', $job->queue());
    }

    #[Test]
    public function customQueueName(): void
    {
        $job = new SendNotificationJob(
            $this->createStub(NotifiableInterface::class),
            $this->createStub(Notification::class),
            $this->createStub(NotificationManagerInterface::class),
            queueName: 'priority-notifications',
        );

        self::assertSame('priority-notifications', $job->queue());
    }

    #[Test]
    public function defaultMaxAttemptsIsThree(): void
    {
        $job = new SendNotificationJob(
            $this->createStub(NotifiableInterface::class),
            $this->createStub(Notification::class),
            $this->createStub(NotificationManagerInterface::class),
        );

        self::assertSame(3, $job->maxAttempts());
    }

    #[Test]
    public function defaultTimeoutIs60Seconds(): void
    {
        $job = new SendNotificationJob(
            $this->createStub(NotifiableInterface::class),
            $this->createStub(Notification::class),
            $this->createStub(NotificationManagerInterface::class),
        );

        self::assertSame(60, $job->timeout());
    }
}
