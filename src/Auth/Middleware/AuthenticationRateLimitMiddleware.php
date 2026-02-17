<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;

use function is_string;

/**
 * Rate limits authentication endpoints to mitigate brute-force attacks.
 *
 * Apply this middleware to login, password-reset, and other authentication
 * routes. Keys rate limits by client IP address (resolved via TrustedProxy
 * when behind a reverse proxy).
 *
 * Default policy: 5 attempts per IP per 15-minute window (CWE-307).
 * On exhaustion, returns 429 Too Many Requests with Retry-After header.
 *
 * This middleware is separate from the generic RateLimitMiddleware because
 * authentication endpoints require a stricter, independent budget. A user
 * who exhausts the global API rate limit should still be locked out of
 * auth endpoints, and vice versa.
 *
 * Compliance: PCI-DSS Req.8.3.4 (account lockout), NIST SP 800-63B,
 * OWASP ASVS V2.2.1 (anti-automation).
 */
#[Api(since: '1.0.0')]
final readonly class AuthenticationRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiterInterface $limiter,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolveClientIp($request);
        $key = 'auth_rate_limit:' . $ip;

        $result = $this->limiter->hit($key);

        if ($result->exceeded()) {
            return Response::json(
                [
                    'error' => 'Too many authentication attempts',
                    'retry_after' => $result->retryAfter,
                ],
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

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        $raw = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($raw) ? $raw : 'unknown';
    }
}
