<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Webhook replay log sub-configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebhookLogConfig
{
    public function __construct(
        public int $ttlSeconds,
        public string $store,
    ) {}

    /**
     * @param array{
     *     ttl_seconds?: int,
     *     store?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            ttlSeconds: $data['ttl_seconds'] ?? 259200,
            store: $data['store'] ?? 'memory',
        );
    }
}
