<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
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
 * Per-IP rate limiting middleware for CMS public endpoints.
 *
 * Applies separate rate limits for content rendering and checkout routes.
 * Uses a fixed-window counter backed by the cache layer, keyed by a hash
 * of the client IP + route group.
 *
 * Returns 429 Too Many Requests with Retry-After and X-RateLimit-* headers
 * when the per-minute limit is exceeded.
 */
#[Internal(reason: 'CMS public rate limiting; middleware implementation')]
final readonly class CmsPublicRateLimitMiddleware implements MiddlewareInterface
{
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private TaggedCacheInterface $cache,
        private CmsConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ipHash = $this->resolveIpHash($request);
        $now = time();

        $group = $this->resolveGroup($request->getUri()->getPath());
        $limit = $this->getLimitForGroup($group);

        $key = sprintf('cms_public_rate:%s:%s:%d', $group, $ipHash, (int) ($now / self::WINDOW_SECONDS));
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
        if (str_starts_with($path, '/checkout')) {
            return 'checkout';
        }

        return 'content';
    }

    private function getLimitForGroup(string $group): int
    {
        return match ($group) {
            'checkout' => $this->config->publicRateLimitCheckout,
            default => $this->config->publicRateLimitContent,
        };
    }

    /**
     * Resolve a stable hash of the client IP address.
     *
     * Uses REMOTE_ADDR only: never trusts X-Forwarded-For or similar
     * headers which are trivially spoofable.
     */
    private function resolveIpHash(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $raw = is_string($ip) ? $ip : 'unknown';

        return hash('xxh3', $raw);
    }

    private function incrementCounter(string $key): int
    {
        $current = $this->cache->get($key);
        $count = ($current !== null && is_numeric($current)) ? ((int) $current + 1) : 1;

        $this->cache->set($key, (string) $count, ['cms_public_rate'], self::WINDOW_SECONDS);

        return $count;
    }
}
