<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for circuit breakers.
 */
#[Api(since: '1.0.0')]
final readonly class CircuitBreakerConfig
{
    public function __construct(
        public int $failureThreshold = 5,
        public int $successThreshold = 2,
        public int $openTimeoutSeconds = 30,
        public int $sampleWindowSeconds = 60,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $failureThreshold */
        $failureThreshold = $data['failure_threshold'] ?? 5;
        /** @var int $successThreshold */
        $successThreshold = $data['success_threshold'] ?? 2;
        /** @var int $openTimeoutSeconds */
        $openTimeoutSeconds = $data['open_timeout_seconds'] ?? 30;
        /** @var int $sampleWindowSeconds */
        $sampleWindowSeconds = $data['sample_window_seconds'] ?? 60;

        return new self(
            failureThreshold: $failureThreshold,
            successThreshold: $successThreshold,
            openTimeoutSeconds: $openTimeoutSeconds,
            sampleWindowSeconds: $sampleWindowSeconds,
        );
    }
}
