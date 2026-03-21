<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Result of webhook processing.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebhookProcessingResult
{
    public function __construct(
        public WebhookProcessingStatus $status,
        public ?string $eventId = null,
        public ?string $error = null,
    ) {}
}
