<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxRequestsPerSecond: is_int($data['max_requests_per_second'] ?? null)
                ? $data['max_requests_per_second'] : 1000,
            burstSize: is_int($data['burst_size'] ?? null) ? $data['burst_size'] : 100,
        );
    }
}
