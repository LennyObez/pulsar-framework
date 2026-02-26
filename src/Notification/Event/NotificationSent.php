<?php

declare(strict_types=1);

namespace Pulsar\Notification\Event;

use Pulsar\Api\Api;

/**
 * Emitted after a notification is successfully delivered through a channel.
 */
#[Api(since: '1.0.0')]
final readonly class NotificationSent extends NotificationEvent
{
    public function __construct(
        string $notificationId,
        string $notifiableId,
        int $occurredAt,
        public string $channel,
    ) {
        parent::__construct($notificationId, $notifiableId, $occurredAt);
    }
}
