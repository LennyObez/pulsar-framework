<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function str_contains;

/**
 * Rate limiting middleware for admin routes.
 *
 * Applies different limits for read, write, and export operations.
 */
#[Internal]
final class AdminRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiterInterface $rateLimiter,
        private readonly AdminConfig $config,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actorId = $identity?->id() ?? 'anonymous';

        $category = $this->categorize($request);
        $key = "admin:{$category}:{$actorId}";

        $result = $this->rateLimiter->hit($key);

        if ($result->exceeded()) {
            return Response::json(
                ['error' => 'Rate limit exceeded', 'retry_after' => $result->retryAfter],
                ResponseStatus::TooManyRequests,
            )->withHeader('Retry-After', (string) $result->retryAfter);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining);
    }

    private function categorize(Request $request): string
    {
        if (str_contains($request->path, '/export')) {
            return 'export';
        }

        return match ($request->method) {
            Method::POST, Method::PUT, Method::PATCH, Method::DELETE => 'write',
            default => 'read',
        };
    }
}
