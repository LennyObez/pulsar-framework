<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;

use function is_string;

/**
 * Middleware that enforces rate limiting on incoming requests.
 *
 * Uses a configurable rate limiter keyed by client IP address.
 * When a TrustedProxy is provided, resolves the real client IP from
 * X-Forwarded-For behind reverse proxies.
 *
 * Supports composite keying (IP + user ID) for authenticated endpoints
 * via a request attribute. Set `rate_limit.user_id` on the request
 * (e.g., via an authentication middleware) to include the user ID
 * in the rate-limit key. This prevents a single authenticated user
 * from consuming the entire IP-based quota on shared networks.
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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->resolveKey($request);
        $result = $this->limiter->hit($key);

        if ($result->exceeded()) {
            return Response::json(
                ['error' => 'Too Many Requests', 'retry_after' => $result->retryAfter],
                ResponseStatus::TooManyRequests->value,
            )
                ->withHeader('Retry-After', (string) $result->retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $result->limit)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining);
    }

    /**
     * Resolve the rate-limit key from the request.
     *
     * Builds a composite key from the client IP and, when present,
     * the authenticated user ID (from the `rate_limit.user_id` request
     * attribute). This prevents a single user from exhausting the
     * IP-based quota on shared networks (e.g., corporate NAT).
     */
    private function resolveKey(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            $ip = $this->trustedProxy->resolveClientIp($request);
        } else {
            $raw = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $ip = is_string($raw) ? $raw : 'unknown';
        }

        $userId = $request->getAttribute('rate_limit.user_id');

        if (is_string($userId) && $userId !== '') {
            return 'rate_limit:' . $ip . ':' . $userId;
        }

        return 'rate_limit:' . $ip;
    }
}
