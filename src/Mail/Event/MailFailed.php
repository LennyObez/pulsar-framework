<?php

declare(strict_types=1);

namespace Pulsar\Mail\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a mail send operation fails.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MailFailed extends MailEvent
{
    public function __construct(
        string $messageId,
        int $occurredAt,
        public string $reason,
        public string $driver,
    ) {
        parent::__construct($messageId, $occurredAt);
    }
}
