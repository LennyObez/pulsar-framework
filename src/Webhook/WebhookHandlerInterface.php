<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Generic webhook event handler contract.
 *
 * Domain-agnostic — handlers receive the event type string and raw payload array.
 * Domain-specific extensions (e.g., payments) can adapt this by creating typed
 * events inside their handler implementations.
 */
#[Api(since: '1.0.0')]
interface WebhookHandlerInterface
{
    /**
     * Handle a verified webhook event.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(string $eventType, array $payload): void;
}
