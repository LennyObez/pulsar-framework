<?php

declare(strict_types=1);

namespace Pulsar\Http\Cache;

use Pulsar\Api\Api;

/**
 * Serializable representation of a cached HTTP response.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CachedResponse
{
    /**
     * @param int $statusCode HTTP status code
     * @param array<string, string[]>|array<array<string>> $headers Response headers
     * @param string $body Response body
     * @param string $etag ETag for conditional requests
     * @param int $createdAt Unix timestamp when cached
     * @param int $expiresAt Unix timestamp when the entry expires
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $etag,
        public int $createdAt,
        public int $expiresAt,
    ) {}

    /**
     * Check if this cached response has expired.
     */
    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    /**
     * Get the remaining TTL in seconds.
     */
    public function remainingTtl(): int
    {
        $remaining = $this->expiresAt - time();

        return $remaining > 0 ? $remaining : 0;
    }
}
