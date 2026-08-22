<?php

declare(strict_types=1);

namespace Pulsar\Http\Cache;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function explode;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function sprintf;
use function str_contains;
use function time;
use function trim;

/**
 * Full-page HTTP response caching middleware with tag-based invalidation.
 *
 * Caches GET/HEAD responses and serves them on subsequent requests.
 * Supports ETag/If-None-Match conditional requests for bandwidth savings.
 * Cache-Control headers are set on responses to control downstream caching.
 *
 * Configure per-route via request attributes:
 *   - `cache.ttl` (int): TTL in seconds (default: 60)
 *   - `cache.tags` (list<string>): Tags for invalidation groups
 *   - `cache.private` (bool): Whether to set Cache-Control: private
 *   - `cache.enabled` (bool): Set to false to skip caching for this route
 * @api
 */
#[Api(since: '1.0.0')]
final class HttpCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheStorageInterface $storage,
        private readonly int $defaultTtl = 60,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only cache safe methods
        $method = $request->getMethod();

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        // Allow per-route opt-out
        if ($request->getAttribute('cache.enabled') === false) {
            return $handler->handle($request);
        }

        $cacheKey = $this->computeCacheKey($request);
        $cached = $this->storage->get($cacheKey);

        if ($cached !== null) {
            // Check If-None-Match for 304
            $ifNoneMatch = $request->getHeaderLine('If-None-Match');

            if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $cached->etag)) {
                return $this->create304Response($request, $cached);
            }

            return $this->createCachedResponse($request, $cached);
        }

        // Cache miss: forward to next handler
        $response = $handler->handle($request);

        // Only cache successful responses
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            return $response;
        }

        // Respect no-store / no-cache directives from the origin.
        // Use str_contains() to match the directive anywhere in the header value,
        // since Cache-Control can contain multiple comma-separated directives
        // (e.g. "private, no-store, max-age=0").
        $cacheControl = $response->getHeaderLine('Cache-Control');

        if (str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'no-cache')) {
            return $response;
        }

        $ttl = $this->resolveTtl($request);
        $tags = $this->resolveTags($request);
        $isPrivate = (bool) $request->getAttribute('cache.private', false);

        $body = (string) $response->getBody();
        $etag = '"' . hash('xxh128', $body) . '"';
        $now = time();

        $cachedResponse = new CachedResponse(
            statusCode: $statusCode,
            headers: $response->getHeaders(),
            body: $body,
            etag: $etag,
            createdAt: $now,
            expiresAt: $now + $ttl,
        );

        $this->storage->set($cacheKey, $cachedResponse, $ttl, $tags);

        // Add cache headers to the live response
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $this->buildCacheControl($ttl, $isPrivate))
            ->withHeader('X-Cache', 'MISS');

        return $response;
    }

    /**
     * Invalidate cached responses by tags.
     *
     * @param list<string> $tags
     */
    public function invalidateByTags(array $tags): void
    {
        $this->storage->invalidateByTags($tags);
    }

    /**
     * Clear the entire response cache.
     */
    public function clearCache(): void
    {
        $this->storage->clear();
    }

    private function computeCacheKey(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $method = $request->getMethod();
        $path = '/' . trim($uri->getPath(), '/');
        $query = $uri->getQuery();

        $parts = [$method, $path];

        if ($query !== '') {
            $parts[] = $query;
        }

        return hash('xxh128', implode("\0", $parts));
    }

    private function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '*') {
            return true;
        }

        // Handle comma-separated ETags
        $etags = explode(',', $ifNoneMatch);

        foreach ($etags as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function resolveTtl(ServerRequestInterface $request): int
    {
        /** @var mixed $ttl */
        $ttl = $request->getAttribute('cache.ttl');

        if ($ttl !== null && is_int($ttl)) {
            return $ttl;
        }

        return $this->defaultTtl;
    }

    /**
     * @return list<string>
     */
    private function resolveTags(ServerRequestInterface $request): array
    {
        /** @var mixed $tags */
        $tags = $request->getAttribute('cache.tags');

        if (is_array($tags)) {
            /** @var list<string> $tags */
            return $tags;
        }

        return [];
    }

    private function buildCacheControl(int $ttl, bool $isPrivate): string
    {
        $directive = $isPrivate ? 'private' : 'public';

        return sprintf('%s, max-age=%d', $directive, $ttl);
    }

    private function create304Response(ServerRequestInterface $request, CachedResponse $cached): ResponseInterface
    {
        return new Response(
            statusCode: 304,
            headers: [
                'ETag' => $cached->etag,
                'Cache-Control' => $this->buildCacheControl($cached->remainingTtl(), false),
                'X-Cache' => 'HIT',
            ],
        );
    }

    private function createCachedResponse(ServerRequestInterface $request, CachedResponse $cached): ResponseInterface
    {
        /** @var array<string, string|list<string>> $headers */
        $headers = $cached->headers;
        $headers['ETag'] = $cached->etag;
        $headers['X-Cache'] = 'HIT';
        $headers['Age'] = (string) (time() - $cached->createdAt);

        return new Response(
            statusCode: $cached->statusCode,
            headers: $headers,
            body: $cached->body,
        );
    }
}
