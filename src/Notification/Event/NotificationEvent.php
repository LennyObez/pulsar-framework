<?php

declare(strict_types=1);

namespace Pulsar\Notification\Event;

use Pulsar\Api\Api;

/**
 * Base class for notification events.
 * @api
 */
#[Api(since: '1.0.0')]
abstract readonly class NotificationEvent
{
    public function __construct(
        public string $notificationId,
        public string $notifiableId,
        public int $occurredAt,
    ) {}
}
