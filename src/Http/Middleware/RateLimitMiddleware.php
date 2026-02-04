<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use function is_string;

use Override;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;

/**
 * Middleware that enforces rate limiting on incoming requests.
 *
 * Uses a configurable rate limiter keyed by client IP address.
 * When a TrustedProxy is provided, resolves the real client IP from
 * X-Forwarded-For behind reverse proxies.
 *
 * Returns 429 Too Many Requests with standard rate-limit headers
 * when the limit is exceeded.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiterInterface $limiter,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        $key = $this->resolveKey($request);
        $result = $this->limiter->hit($key);

        if ($result->exceeded()) {
            return Response::json(
                ['error' => 'Too Many Requests', 'retry_after' => $result->retryAfter],
                ResponseStatus::TooManyRequests,
            )
                ->withHeader('Retry-After', (string) $result->retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $result->limit)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining);
    }

    /**
     * Resolve the rate-limit key from the request.
     *
     * Uses TrustedProxy for IP resolution when available, otherwise
     * falls back to REMOTE_ADDR.
     */
    private function resolveKey(Request $request): string
    {
        if ($this->trustedProxy !== null) {
            return 'rate_limit:' . $this->trustedProxy->resolveClientIp($request);
        }

        $ip = $request->server('REMOTE_ADDR');

        return 'rate_limit:' . (is_string($ip) ? $ip : 'unknown');
    }
}
