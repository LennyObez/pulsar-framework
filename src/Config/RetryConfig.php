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
final readonly class RetryConfig implements ReportsUnknownKeys
{
    /** Keys read from the `retry` sub-array of config/resilience.php. */
    private const array KNOWN_KEYS = [
        'max_attempts', 'base_delay_ms', 'max_delay_ms', 'multiplier', 'jitter',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `retry` array that this
     *     DTO does not read — a misspelled backoff key leaves the caller retrying on
     *     defaults, which is how a tuned retry budget silently becomes an untuned one.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 100,
        public int $maxDelayMs = 5000,
        public float $multiplier = 2.0,
        public bool $jitter = true,
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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
