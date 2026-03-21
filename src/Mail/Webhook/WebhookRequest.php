<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Api;

/**
 * Incoming webhook request from a mail provider.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebhookRequest
{
    /**
     * @param array<string, string> $headers HTTP headers from the webhook request
     */
    public function __construct(
        public string $payload,
        public array $headers,
        public string $sourceIp,
        public int $timestamp,
        public string $provider,
    ) {}
}
