<?php

declare(strict_types=1);

namespace Pulsar\Notification\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a notification delivery fails on a channel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class NotificationFailed extends NotificationEvent
{
    public function __construct(
        string $notificationId,
        string $notifiableId,
        int $occurredAt,
        public string $channel,
        public string $reason,
    ) {
        parent::__construct($notificationId, $notifiableId, $occurredAt);
    }
}
