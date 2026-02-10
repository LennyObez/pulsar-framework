<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of processing an inbound webhook.
 */
#[Api(since: '1.0.0')]
final readonly class WebhookResult
{
    public function __construct(
        public bool $accepted,
        public string $eventId,
        public WebhookEventType $eventType,
        public ?string $messageId = null,
    ) {}

    #[NoDiscard]
    public static function accepted(string $eventId, WebhookEventType $eventType, ?string $messageId = null): self
    {
        return new self(
            accepted: true,
            eventId: $eventId,
            eventType: $eventType,
            messageId: $messageId,
        );
    }

    #[NoDiscard]
    public static function rejected(string $eventId, WebhookEventType $eventType): self
    {
        return new self(
            accepted: false,
            eventId: $eventId,
            eventType: $eventType,
        );
    }
}
