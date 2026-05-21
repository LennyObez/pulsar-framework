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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
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
     *
     * F7.2: when neither a TrustedProxy chain nor REMOTE_ADDR can
     * yield an IP, the resolver previously fell back to the literal
     * string `unknown` — every client without an identifiable IP
     * shared one bucket and the rate limiter degraded to no limit
     * at all (fail-open). The fallback now hashes the User-Agent
     * (truncated SHA-256) so distinct clients still get distinct
     * buckets, and ultimately falls back to a hash of the request
     * URI + method as a last-resort distinct key. Both paths keep
     * the limiter working under partial-info conditions instead of
     * silently disabling itself.
     */
    private function resolveKey(ServerRequestInterface $request): string
    {
        $ip = $this->resolveClientIp($request);
        /** @var mixed $userId */
        $userId = $request->getAttribute('rate_limit.user_id');

        if (is_string($userId) && $userId !== '') {
            return 'rate_limit:' . $ip . ':' . $userId;
        }

        return 'rate_limit:' . $ip;
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

        // F7.2 fallback 1: hash of User-Agent. Distinct clients usually
        // ship distinct UAs, so this gives the limiter a per-client
        // bucket even without an IP. 16 hex chars = 64 bits of
        // collision resistance — adequate for a per-client bucket key.
        $userAgent = $request->getHeaderLine('User-Agent');

        if ($userAgent !== '') {
            return 'ua-' . substr(hash('sha256', $userAgent), 0, 16);
        }

        // F7.2 fallback 2: hash of method + URI. Same client, different
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
