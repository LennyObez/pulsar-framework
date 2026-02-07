<?php

declare(strict_types=1);

namespace Pulsar\Mail\Event;

use Pulsar\Api\Api;
use Pulsar\Config\MailEncryptionPolicy;

/**
 * Emitted when a message is sent without encryption due to a fallback from the configured policy.
 */
#[Api(since: '1.0.0')]
final readonly class MailEncryptionFallbackEvent extends MailEvent
{
    public function __construct(
        string $messageId,
        int $occurredAt,
        public string $recipientEmail,
        public string $reason,
        public MailEncryptionPolicy $policy,
    ) {
        parent::__construct($messageId, $occurredAt);
    }
}
