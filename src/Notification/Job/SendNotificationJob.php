<?php

declare(strict_types=1);

namespace Pulsar\Notification\Job;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationManagerInterface;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;

/**
 * Queueable job that sends a notification through the notification manager.
 *
 * Used for asynchronous notification delivery via the queue system.
 */
#[Internal]
final class SendNotificationJob implements QueueableInterface
{
    /**
     * @param list<string>|null $channels Specific channels to send through (null = use notification's via())
     */
    public function __construct(
        private readonly NotifiableInterface $notifiable,
        private readonly Notification $notification,
        private readonly NotificationManagerInterface $notificationManager,
        private readonly ?array $channels = null,
        private readonly string $queueName = 'notifications',
        private readonly int $maxAttemptCount = 3,
        private readonly int $timeoutSeconds = 60,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        $this->notificationManager->sendNow($this->notifiable, $this->notification, $this->channels);
    }

    #[Override]
    public function queue(): string
    {
        return $this->queueName;
    }

    #[Override]
    public function maxAttempts(): int
    {
        return $this->maxAttemptCount;
    }

    #[Override]
    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }
}
