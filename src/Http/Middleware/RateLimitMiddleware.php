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
     * Uses TrustedProxy for IP resolution when available, otherwise
     * falls back to REMOTE_ADDR.
     */
    private function resolveKey(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return 'rate_limit:' . $this->trustedProxy->resolveClientIp($request);
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return 'rate_limit:' . (is_string($ip) ? $ip : 'unknown');
    }
}
