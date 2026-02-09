<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Idempotency sub-configuration.
 */
#[Api]
final readonly class IdempotencyConfig
{
    public function __construct(
        public int $ttlSeconds,
        public string $store,
        public int $maxKeyLength,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $ttlSeconds */
        $ttlSeconds = $data['ttl_seconds'] ?? 86400;
        /** @var string $store */
        $store = $data['store'] ?? 'memory';
        /** @var int $maxKeyLength */
        $maxKeyLength = $data['max_key_length'] ?? 256;

        return new self(
            ttlSeconds: $ttlSeconds,
            store: $store,
            maxKeyLength: $maxKeyLength,
        );
    }
}
