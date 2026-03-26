<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for retry policies.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RetryConfig
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 100,
        public int $maxDelayMs = 5000,
        public float $multiplier = 2.0,
        public bool $jitter = true,
    ) {}

    /**
     * @param array{
     *     max_attempts?: int,
     *     base_delay_ms?: int,
     *     max_delay_ms?: int,
     *     multiplier?: float|int,
     *     jitter?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxAttempts: $data['max_attempts'] ?? 3,
            baseDelayMs: $data['base_delay_ms'] ?? 100,
            maxDelayMs: $data['max_delay_ms'] ?? 5000,
            multiplier: (float) ($data['multiplier'] ?? 2.0),
            jitter: (bool) ($data['jitter'] ?? true),
        );
    }
}
