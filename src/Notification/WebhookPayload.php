<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * DTO representing a webhook notification payload.
 */
#[Api(since: '1.0.0')]
final readonly class WebhookPayload
{
    /**
     * @param string               $url     Target webhook URL
     * @param array<string, mixed> $data    Payload data (JSON-serializable)
     * @param array<string, string> $headers HTTP headers
     * @param string               $method  HTTP method (default: POST)
     */
    public function __construct(
        public string $url,
        public array $data = [],
        public array $headers = [],
        public string $method = 'POST',
    ) {}
}
