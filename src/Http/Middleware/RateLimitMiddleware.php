<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use function is_string;

use Pulsar\Http\RateLimit\RateLimiter;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Middleware that enforces rate limiting on incoming requests.
 *
 * Uses a fixed-window rate limiter keyed by client IP address.
 * Returns 429 Too Many Requests with standard rate-limit headers
 * when the limit is exceeded.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiter $limiter,
    ) {}

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
     * Default: client IP address from REMOTE_ADDR server variable.
     */
    private function resolveKey(Request $request): string
    {
        $ip = $request->server('REMOTE_ADDR');

        return 'rate_limit:' . (is_string($ip) ? $ip : 'unknown');
    }
}
