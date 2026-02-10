<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function hash;
use function is_string;
use function max;
use function sprintf;
use function str_starts_with;
use function time;

/**
 * Per-IP, per-action rate limiting for forum endpoints.
 *
 * Enforces separate limits for reports (5/hr), threads (20/hr), and posts (30/hr).
 * Uses a fixed-window counter backed by TaggedCacheInterface, keyed by a hash
 * of the client IP + action group.
 *
 * Returns 429 Too Many Requests with Retry-After and X-RateLimit-* headers
 * when the per-hour limit is exceeded.
 */
#[Internal(reason: 'Forum rate limiting middleware — implementation detail')]
final readonly class ForumRateLimitMiddleware implements MiddlewareInterface
{
    private const int WINDOW_SECONDS = 3600;
    private const int LIMIT_REPORTS = 5;
    private const int LIMIT_THREADS = 20;
    private const int LIMIT_POSTS = 30;
    private const int LIMIT_VOTES = 60;
    private const int LIMIT_DEFAULT = 120;

    public function __construct(
        private TaggedCacheInterface $cache,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return $handler->handle($request);
        }

        $ipHash = $this->resolveIpHash($request);
        $now = time();

        $group = $this->resolveGroup($request->getUri()->getPath());
        $limit = $this->getLimitForGroup($group);

        $windowId = (int) ($now / self::WINDOW_SECONDS);
        $key = sprintf('forum_rate:%s:%s:%d', $group, $ipHash, $windowId);
        $count = $this->incrementCounter($key);

        if ($count > $limit) {
            $retryAfter = self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS);

            return Response::json(
                ['error' => 'Too Many Requests', 'retry_after' => $retryAfter],
                ResponseStatus::TooManyRequests->value,
            )
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $limit)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $remaining = max(0, $limit - $count);
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    private function resolveGroup(string $path): string
    {
        if (str_starts_with($path, '/api/v1/forum/reports') || str_starts_with($path, '/forum/reports')) {
            return 'reports';
        }

        if (str_starts_with($path, '/api/v1/forum/votes') || str_starts_with($path, '/forum/votes')) {
            return 'votes';
        }

        if (str_starts_with($path, '/api/v1/forum/threads') || str_starts_with($path, '/forum/threads')) {
            return 'threads';
        }

        if (str_starts_with($path, '/api/v1/forum/posts') || str_starts_with($path, '/forum/posts')) {
            return 'posts';
        }

        return 'default';
    }

    private function getLimitForGroup(string $group): int
    {
        return match ($group) {
            'reports' => self::LIMIT_REPORTS,
            'threads' => self::LIMIT_THREADS,
            'posts' => self::LIMIT_POSTS,
            'votes' => self::LIMIT_VOTES,
            default => self::LIMIT_DEFAULT,
        };
    }

    private function resolveIpHash(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $raw = is_string($ip) ? $ip : 'unknown';

        return hash('xxh3', $raw);
    }

    private function incrementCounter(string $key): int
    {
        $current = $this->cache->get($key);
        $count = is_numeric($current) ? ((int) $current + 1) : 1;

        $this->cache->set($key, (string) $count, ['forum_rate'], self::WINDOW_SECONDS);

        return $count;
    }
}
