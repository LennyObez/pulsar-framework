<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

/**
 * Rate limit configuration for a specific tier or route group.
 */
#[Api(since: '1.0.0')]
final readonly class RateLimitTier
{
    public function __construct(
        public int $maxAttempts,
        public int $windowSeconds,
    ) {}
}
