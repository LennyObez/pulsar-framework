<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Result of webhook processing.
 */
#[Api]
final readonly class WebhookProcessingResult
{
    public function __construct(
        public WebhookProcessingStatus $status,
        public ?string $eventId = null,
        public ?string $error = null,
    ) {}
}
