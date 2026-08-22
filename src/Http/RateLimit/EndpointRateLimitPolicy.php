<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Per-endpoint rate limit policy.
 *
 * Allows stricter limits for sensitive endpoints like login, API auth,
 * and password reset while allowing more lenient limits for public reads.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EndpointRateLimitPolicy
{
    public function __construct(
        public string $pattern,
        public int $maxAttempts,
        public int $windowSeconds,
    ) {}

    /**
     * @param array{
     *     pattern?: string,
     *     max_attempts?: int,
     *     window_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            pattern: $data['pattern'] ?? '',
            maxAttempts: $data['max_attempts'] ?? 60,
            windowSeconds: $data['window_seconds'] ?? 60,
        );
    }
}
