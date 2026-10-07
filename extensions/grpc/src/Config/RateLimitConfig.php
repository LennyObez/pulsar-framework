<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Rate limiting configuration for gRPC calls.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RateLimitConfig
{
    public function __construct(
        public int $maxRequestsPerSecond = 1000,
        public int $burstSize = 100,
    ) {}

    /**
     * @param array{
     *     max_requests_per_second?: int,
     *     burst_size?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxRequestsPerSecond: $data['max_requests_per_second'] ?? 1000,
            burstSize: $data['burst_size'] ?? 100,
        );
    }
}
