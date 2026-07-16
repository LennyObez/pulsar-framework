<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function hash;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function time;

/**
 * Per-IP rate limiting middleware for comment submissions.
 *
 * Implements a sliding window counter using the cache layer.
 * Keyed by a hash of the client IP (from REMOTE_ADDR, not spoofable headers)
 * to prevent trivial bypass.
 *
 * Returns 429 Too Many Requests with Retry-After header when exceeded.
 *
 * @psalm-api Registered with the router middleware pipeline by the
 *            CmsCoreServiceProvider; not new'd by name.
 */
#[Internal(reason: 'CMS middleware; not a public API surface')]
final readonly class CommentRateLimitMiddleware implements MiddlewareInterface
{
    private const int DEFAULT_PER_MINUTE = 5;
    private const int DEFAULT_PER_HOUR = 30;

    public function __construct(
        private TaggedCacheInterface $cache,
        private int $rateLimitPerMinute = self::DEFAULT_PER_MINUTE,
        private int $rateLimitPerHour = self::DEFAULT_PER_HOUR,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ipHash = $this->resolveIpHash($request);
        $now = time();

        // Check per-minute limit
        $minuteKey = CmsCacheKeys::commentRateMinute($ipHash, (int) ($now / 60));
        $minuteCount = $this->incrementCounter($minuteKey, 60);

        if ($minuteCount > $this->rateLimitPerMinute) {
            $retryAfter = 60 - ($now % 60);

            return Response::json(
                ['error' => 'Too Many Requests', 'retry_after' => $retryAfter],
                ResponseStatus::TooManyRequests->value,
            )
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $this->rateLimitPerMinute)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        // Check per-hour limit
        $hourKey = CmsCacheKeys::commentRateHour($ipHash, (int) ($now / 3600));
        $hourCount = $this->incrementCounter($hourKey, 3600);

        if ($hourCount > $this->rateLimitPerHour) {
            $retryAfter = 3600 - ($now % 3600);

            return Response::json(
                ['error' => 'Too Many Requests', 'retry_after' => $retryAfter],
                ResponseStatus::TooManyRequests->value,
            )
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $this->rateLimitPerHour)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $remaining = max(0, $this->rateLimitPerMinute - $minuteCount);

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->rateLimitPerMinute)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    /**
     * Resolve a stable hash of the client IP address.
     *
     * Uses REMOTE_ADDR only: never trusts X-Forwarded-For or similar
     * headers which are trivially spoofable.
     */
    private function resolveIpHash(ServerRequestInterface $request): string
    {
        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $raw = is_string($ip) ? $ip : 'unknown';

        return hash('xxh3', $raw);
    }

    /**
     * Increment a counter in cache with the given TTL.
     */
    private function incrementCounter(string $key, int $ttlSeconds): int
    {
        /** @var mixed $current */
        $current = $this->cache->get($key);
        $count = (is_string($current) || is_int($current)) && is_numeric($current)
            ? ((int) $current + 1)
            : 1;

        $this->cache->set($key, (string) $count, [CmsCacheKeys::TAG_COMMENT_RATE], $ttlSeconds);

        return $count;
    }
}
