<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\SlidingWindowRateLimiter;
use Pulsar\Http\ResponseStatus;

use function in_array;
use function str_contains;

/**
 * Rate limiting middleware for admin routes.
 *
 * Applies a distinct limit for read, write, and export operations, each
 * over the configured window, taken from {@see AdminRateLimitConfig}.
 * Each category is counted in its own bucket so a burst of writes never
 * consumes a reader's budget (and vice versa), and the limits an operator
 * configures (read/write/export) are actually enforced rather than sharing
 * a single global allowance.
 */
#[Internal]
final readonly class AdminRateLimitMiddleware implements MiddlewareInterface
{
    private RateLimiterInterface $readLimiter;
    private RateLimiterInterface $writeLimiter;
    private RateLimiterInterface $exportLimiter;

    public function __construct(AdminRateLimitConfig $config)
    {
        $this->readLimiter = new SlidingWindowRateLimiter($config->readLimit, $config->windowSeconds);
        $this->writeLimiter = new SlidingWindowRateLimiter($config->writeLimit, $config->windowSeconds);
        $this->exportLimiter = new SlidingWindowRateLimiter($config->exportLimit, $config->windowSeconds);
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actorId = $identity?->id() ?? 'anonymous';

        $category = $this->categorize($request);
        $limiter = match ($category) {
            'export' => $this->exportLimiter,
            'write' => $this->writeLimiter,
            default => $this->readLimiter,
        };

        $key = "admin:$category:$actorId";

        $result = $limiter->hit($key);

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
