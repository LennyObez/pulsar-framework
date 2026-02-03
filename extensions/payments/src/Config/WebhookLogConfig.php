<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Webhook replay log sub-configuration.
 */
#[Api]
final readonly class WebhookLogConfig
{
    public function __construct(
        public int $ttlSeconds,
        public string $store,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            ttlSeconds: (int) ($data['ttl_seconds'] ?? 259200),
            store: (string) ($data['store'] ?? 'memory'),
        );
    }
}
