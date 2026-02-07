<?php

declare(strict_types=1);

namespace Pulsar\Mail\Event;

use Pulsar\Api\Api;

/**
 * Base class for mail events.
 */
#[Api(since: '1.0.0')]
abstract readonly class MailEvent
{
    public function __construct(
        public string $messageId,
        public int $occurredAt,
    ) {}
}
