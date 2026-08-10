<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitKeyStrategy;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;

use function hash;
use function is_string;
use function substr;

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
        private RateLimitKeyStrategy $keyStrategy = RateLimitKeyStrategy::Ip,
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
     *
     * When neither a TrustedProxy chain nor REMOTE_ADDR can yield an
     * IP, the fallback must still produce a key that varies per client.
     * A constant fallback would put every client without an identifiable
     * IP in one bucket, which is a fail-open: the limiter stops limiting.
     * So the first fallback hashes the User-Agent (truncated SHA-256),
     * and the last resort hashes the request URI + method. Both keep the
     * limiter working under partial-info conditions.
     */
    private function resolveKey(ServerRequestInterface $request): string
    {
        return match ($this->keyStrategy) {
            RateLimitKeyStrategy::Route => 'rate_limit:route:' . $this->resolveRoute($request),
            RateLimitKeyStrategy::IpAndRoute => 'rate_limit:' . $this->resolveClientIp($request)
                . ':route:' . $this->resolveRoute($request),
            RateLimitKeyStrategy::Ip => $this->resolveIpKey($request),
        };
    }

    /**
     * Per-client key: IP plus the authenticated user id when present, so a single
     * user cannot exhaust the IP quota on a shared network (corporate NAT).
     */
    private function resolveIpKey(ServerRequestInterface $request): string
    {
        $ip = $this->resolveClientIp($request);
        /** @var mixed $userId */
        $userId = $request->getAttribute('rate_limit.user_id');

        if (is_string($userId) && $userId !== '') {
            return 'rate_limit:' . $ip . ':' . $userId;
        }

        return 'rate_limit:' . $ip;
    }

    /**
     * Identify the matched route for route-scoped strategies. Prefers the
     * router-set `_route` attribute; falls back to method + path so an
     * unmatched/ad-hoc request still buckets deterministically.
     */
    private function resolveRoute(ServerRequestInterface $request): string
    {
        /** @var mixed $route */
        $route = $request->getAttribute('_route');

        if (is_string($route) && $route !== '') {
            return $route;
        }

        return $request->getMethod() . ' ' . $request->getUri()->getPath();
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $raw */
        $raw = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        // Fallback 1: hash of User-Agent. Distinct clients usually
        // ship distinct UAs, so this gives the limiter a per-client
        // bucket even without an IP. 16 hex chars = 64 bits of
        // collision resistance — adequate for a per-client bucket key.
        $userAgent = $request->getHeaderLine('User-Agent');

        if ($userAgent !== '') {
            return 'ua-' . substr(hash('sha256', $userAgent), 0, 16);
        }

        // Fallback 2: hash of method + URI. Same client, different
        // endpoints, no UA → at least the buckets differ per endpoint.
        // This is the floor: a request truly without REMOTE_ADDR /
        // X-Forwarded-For / User-Agent is exotic enough that giving
        // it its own per-endpoint bucket is acceptable.
        return 'req-' . substr(hash(
            'sha256',
            $request->getMethod() . '|' . (string) $request->getUri(),
        ), 0, 16);
    }
}
