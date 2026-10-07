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
use Pulsar\Routing\MatchedRoute;

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
 *
 * ## Route-scoped buckets are keyed from the dispatched route
 *
 * The route-scoped strategies used to read the `_route` request attribute and
 * accept it only `if (is_string($route))`. That attribute is a
 * {@see MatchedRoute} object — {@see \Pulsar\Core\Kernel::dispatchRoute()}
 * writes it as one — so the test was false on every request that ever reached
 * this middleware, and every route-scoped bucket silently fell through to the
 * method-and-path fallback. `/users/1` and `/users/2` were therefore different
 * buckets on one route: a route-scoped limit was evaded by varying the id, and
 * the number of buckets the limiter store held was bounded by the URL space
 * rather than by the route table.
 *
 * Replacing the dead test with a live read of the same attribute would have
 * swapped a broken control for an evadable one — `_route` is rewritable by every
 * frame between routing and here, and this middleware is piped route-level, so
 * another route middleware sits in front of it. The route arrives as an argument
 * instead: {@see DispatchedRouteAwareInterface} is bound by the pipeline the
 * kernel hands the dispatched route to, before the chain is built and therefore
 * before any frame that could write an attribute exists.
 *
 * An unbound copy — piped globally, where routing has not happened yet — keeps
 * the documented method-and-path fallback, which is the honest key for a request
 * whose route is not decided.
 */
final readonly class RateLimitMiddleware implements DispatchedRouteAwareInterface, MiddlewareInterface
{
    public function __construct(
        private RateLimiterInterface $limiter,
        private ?TrustedProxy $trustedProxy = null,
        private RateLimitKeyStrategy $keyStrategy = RateLimitKeyStrategy::Ip,
        private ?MatchedRoute $dispatchedRoute = null,
    ) {}

    /**
     * Bind a copy of this middleware to the route the kernel is dispatching.
     *
     * A copy, not a mutation: one instance serves every request for the process
     * lifetime, and a persistent worker interleaves Fiber-suspended requests
     * through it, so a route stored on the shared object would be another
     * request's route. The class is readonly, so the copy is a construction
     * rather than a `clone`.
     */
    #[Override]
    public function forDispatchedRoute(MatchedRoute $route): self
    {
        return new self($this->limiter, $this->trustedProxy, $this->keyStrategy, $route);
    }

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
     * Identify the route for route-scoped strategies.
     *
     * The dispatched route's NAME when it has one, its PATTERN otherwise. Both
     * come from the route table, so the number of distinct buckets a route-scoped
     * strategy can ever create is the size of that table — which is what makes it
     * a route-scoped limit rather than a per-URL one. The name is preferred
     * because two routes may share a pattern under different hosts or methods,
     * and a name distinguishes them where a pattern does not.
     *
     * Falls back to method + path only when this copy was never bound to a
     * dispatch: the global pipeline runs before routing, so there is no route to
     * name and inventing one would be a guess. That fallback is per-URL by
     * construction, which is why it is the answer for a request with no route
     * rather than the answer for a route-scoped strategy.
     */
    private function resolveRoute(ServerRequestInterface $request): string
    {
        $route = $this->dispatchedRoute;

        if ($route !== null) {
            $name = $route->getName();

            return $name !== null && $name !== '' ? $name : $route->route->path;
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
