<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Webhook replay log sub-configuration.
 */
#[Api(since: '1.0.0')]
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
        /** @var int $ttlSeconds */
        $ttlSeconds = $data['ttl_seconds'] ?? 259200;
        /** @var string $store */
        $store = $data['store'] ?? 'memory';

        return new self(
            ttlSeconds: $ttlSeconds,
            store: $store,
        );
    }
}
