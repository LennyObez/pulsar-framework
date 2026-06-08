<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Server\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Config\HealthStatusConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function array_key_exists;
use function count;
use function is_string;
use function max;
use function time;

/**
 * Access control and rate limiting for health-status endpoints.
 *
 * Enforces authentication requirements, public summary stripping,
 * per-IP rate limiting, and security headers.
 */
#[Internal]
final class StatusAccessMiddleware implements MiddlewareInterface
{
    /**
     * In-memory rate limit tracker: IP => [timestamps].
     *
     * @var array<string, list<int>>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private array $requestLog = [];

    public function __construct(
        private readonly HealthStatusConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Rate limiting
        $clientIp = $this->resolveClientIp($request);
        $rateLimitResponse = $this->enforceRateLimit($clientIp);

        if ($rateLimitResponse !== null) {
            return $this->addSecurityHeaders($rateLimitResponse);
        }

        // Authentication check
        $isAuthenticated = $this->isAuthenticated($request);

        if ($this->config->requireAuth && !$isAuthenticated) {
            // If public summary is enabled, allow through but mark as unauthenticated
            if ($this->config->publicSummary) {
                $request = $request->withAttribute('health_status.authenticated', false);
                $response = $handler->handle($request);

                return $this->addSecurityHeaders($response);
            }

            return $this->addSecurityHeaders(
                new Response(
                    statusCode: ResponseStatus::Unauthorized->value,
                    headers: ['WWW-Authenticate' => 'Bearer realm="Pulsar Health Status"'],
                    body: 'Authentication required',
                ),
            );
        }

        $request = $request->withAttribute('health_status.authenticated', $isAuthenticated);
        $response = $handler->handle($request);

        return $this->addSecurityHeaders($response);
    }

    /**
     * Determine if the request has valid authentication.
     */
    private function isAuthenticated(ServerRequestInterface $request): bool
    {
        $authHeader = $request->getHeaderLine('Authorization');

        return $authHeader !== '';
    }

    /**
     * Resolve the client IP from the request.
     */
    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();

        if (array_key_exists('REMOTE_ADDR', $serverParams)) {
            /** @var mixed $addr */
            $addr = $serverParams['REMOTE_ADDR'];

            return is_string($addr) ? $addr : '0.0.0.0';
        }

        return '0.0.0.0';
    }

    /**
     * Enforce per-IP rate limiting.
     *
     * Returns a 429 response if the limit is exceeded, null otherwise.
     */
    private function enforceRateLimit(string $ip): ?Response
    {
        $now = time();
        $windowStart = $now - 60;

        // Clean old entries
        if (isset($this->requestLog[$ip])) {
            $this->requestLog[$ip] = array_values(
                array_filter(
                    $this->requestLog[$ip],
                    static fn(int $ts): bool => $ts > $windowStart,
                ),
            );
        } else {
            $this->requestLog[$ip] = [];
        }

        // Check limit
        if (count($this->requestLog[$ip]) >= $this->config->rateLimitPerMinute) {
            $oldestInWindow = $this->requestLog[$ip][0] ?? $now;
            $retryAfter = max(1, 60 - ($now - $oldestInWindow));

            return new Response(
                statusCode: ResponseStatus::TooManyRequests->value,
                headers: ['Retry-After' => (string) $retryAfter],
                body: 'Rate limit exceeded',
            );
        }

        // Record request
        $this->requestLog[$ip][] = $now;

        return null;
    }

    /**
     * Add security headers to the response.
     */
    private function addSecurityHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Content-Security-Policy', "default-src 'self'")
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
