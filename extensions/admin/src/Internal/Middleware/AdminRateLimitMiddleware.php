<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\ResponseStatus;

use function in_array;
use function str_contains;

/**
 * Rate limiting middleware for admin routes.
 *
 * Applies different limits for read, write, and export operations.
 */
#[Internal]
final readonly class AdminRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiterInterface $rateLimiter,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actorId = $identity?->id() ?? 'anonymous';

        $category = $this->categorize($request);
        $key = "admin:$category:$actorId";

        $result = $this->rateLimiter->hit($key);

        if ($result->exceeded()) {
            return Response::json(
                ['error' => 'Rate limit exceeded', 'retry_after' => $result->retryAfter],
                ResponseStatus::TooManyRequests->value,
            )->withHeader('Retry-After', (string) $result->retryAfter);
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining);
    }

    private function categorize(ServerRequestInterface $request): string
    {
        if (str_contains($request->getUri()->getPath(), '/export')) {
            return 'export';
        }

        return in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? 'write'
            : 'read';
    }
}
