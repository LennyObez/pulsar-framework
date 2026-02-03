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
        return new self(
            ttlSeconds: (int) ($data['ttl_seconds'] ?? 86400),
            store: (string) ($data['store'] ?? 'memory'),
            maxKeyLength: (int) ($data['max_key_length'] ?? 256),
        );
    }
}
