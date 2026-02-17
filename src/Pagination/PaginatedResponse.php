<?php

declare(strict_types=1);

namespace Pulsar\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * API response wrapper that formats a PaginationResult into JSON.
 *
 * Provides a standardized API response format:
 * ```json
 * {
 *   "items": [...],
 *   "meta": {
 *     "current_page": 1,
 *     "per_page": 15,
 *     "total": 150,
 *     "last_page": 10,
 *     "has_more": true
 *   }
 * }
 * ```
 *
 * @template T
 */
#[Api(since: '1.0.0')]
final readonly class PaginatedResponse
{
    /**
     * @param PaginationResult<T> $result
     * @param int $statusCode HTTP status code
     * @param array<string, string> $headers Additional response headers
     */
    public function __construct(
        public PaginationResult $result,
        public int $statusCode = 200,
        public array $headers = [],
    ) {}

    /**
     * Create from a Paginator instance.
     *
     * @template U
     * @param Paginator<U> $paginator
     * @return self<U>
     */
    #[NoDiscard]
    public static function fromPaginator(Paginator $paginator, int $statusCode = 200): self
    {
        return new self($paginator->toResult(), $statusCode);
    }

    /**
     * Serialize the response body to JSON.
     */
    #[NoDiscard]
    public function toJson(): string
    {
        return json_encode($this->result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get all response headers including Content-Type.
     *
     * @return array<string, string>
     */
    #[NoDiscard]
    public function getHeaders(): array
    {
        return ['Content-Type' => 'application/json', ...$this->headers];
    }
}
