<?php

declare(strict_types=1);

namespace Pulsar\Mail\Event;

use Pulsar\Api\Api;

/**
 * Emitted after a mail message is successfully sent.
 */
#[Api(since: '1.0.0')]
final readonly class MailSent extends MailEvent
{
    public function __construct(
        string $messageId,
        int $occurredAt,
        public int $recipientCount,
        public string $driver,
    ) {
        parent::__construct($messageId, $occurredAt);
    }
}
