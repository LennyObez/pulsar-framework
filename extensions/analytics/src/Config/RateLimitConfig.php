<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Rate limiting configuration for the analytics collection endpoint.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RateLimitConfig
{
    /**
     * @param int $maxEventsPerIpPerMinute Maximum events accepted per IP per minute
     * @param int $burst Additional burst capacity above the sustained rate
     */
    public function __construct(
        public int $maxEventsPerIpPerMinute = 30,
        public int $burst = 5,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxEventsPerIpPerMinute: Coerce::int($data['max_events_per_ip_per_minute'] ?? null, 30),
            burst: Coerce::int($data['burst'] ?? null, 5),
        );
    }
}
