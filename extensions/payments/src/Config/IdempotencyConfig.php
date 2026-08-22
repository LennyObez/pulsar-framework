<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Idempotency sub-configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IdempotencyConfig
{
    public function __construct(
        public int $ttlSeconds,
        public string $store,
        public int $maxKeyLength,
    ) {}

    /**
     * @param array{
     *     ttl_seconds?: int,
     *     store?: string,
     *     max_key_length?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            ttlSeconds: $data['ttl_seconds'] ?? 86400,
            store: $data['store'] ?? 'memory',
            maxKeyLength: $data['max_key_length'] ?? 256,
        );
    }
}
