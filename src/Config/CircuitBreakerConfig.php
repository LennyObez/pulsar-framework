<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for circuit breakers.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CircuitBreakerConfig implements ReportsUnknownKeys
{
    /** Keys read from the `circuit_breaker` sub-array of config/resilience.php. */
    private const array KNOWN_KEYS = [
        'failure_threshold', 'success_threshold', 'open_timeout_seconds', 'sample_window_seconds',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `circuit_breaker` array
     *     that this DTO does not read — a misspelled threshold leaves the breaker
     *     tripping at 5 failures regardless of what the file says it should do.
     */
    public function __construct(
        public int $failureThreshold = 5,
        public int $successThreshold = 2,
        public int $openTimeoutSeconds = 30,
        public int $sampleWindowSeconds = 60,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     failure_threshold?: int,
     *     success_threshold?: int,
     *     open_timeout_seconds?: int,
     *     sample_window_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            failureThreshold: $data['failure_threshold'] ?? 5,
            successThreshold: $data['success_threshold'] ?? 2,
            openTimeoutSeconds: $data['open_timeout_seconds'] ?? 30,
            sampleWindowSeconds: $data['sample_window_seconds'] ?? 60,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
