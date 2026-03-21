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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $maxAttempts */
        $maxAttempts = $data['max_attempts'] ?? 3;
        /** @var int $baseDelayMs */
        $baseDelayMs = $data['base_delay_ms'] ?? 100;
        /** @var int $maxDelayMs */
        $maxDelayMs = $data['max_delay_ms'] ?? 5000;
        /** @var float $multiplier */
        $multiplier = $data['multiplier'] ?? 2.0;

        return new self(
            maxAttempts: $maxAttempts,
            baseDelayMs: $baseDelayMs,
            maxDelayMs: $maxDelayMs,
            multiplier: $multiplier,
            jitter: (bool) ($data['jitter'] ?? true),
        );
    }
}
