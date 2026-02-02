<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

/**
 * Result of a rate limit check.
 */
readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
    ) {}

    /**
     * Check if the request was rate-limited.
     */
    public function exceeded(): bool
    {
        return !$this->allowed;
    }
}
