<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function array_key_exists;
use function array_values;
use function hash;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function str_starts_with;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

/**
 * Full-page cache middleware for CMS content pages.
 *
 * Computes a cache key from tenant, locale, and path, then checks
 * TaggedCacheInterface for a cached response. On miss, captures the
 * response, stores it with content-aware tags, and serves it.
 *
 * Bypass conditions:
 * - Authenticated admin users (any cms.* role)
 * - Non-GET/HEAD methods
 * - _nocache query parameter present
 *
 * @psalm-api Registered with the router middleware pipeline by the
 *            CmsCoreServiceProvider; not new'd by name.
 */
#[Internal(reason: 'CMS middleware; not a public API surface')]
final readonly class CmsPageCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TaggedCacheInterface $cache,
        private CmsCacheConfig $cacheConfig,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldBypass($request)) {
            return $handler->handle($request);
        }

        $cacheKey = $this->computeCacheKey($request);

        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            /** @var array{body: string, status: int, headers: array<string, string>} $decoded */
            $decoded = json_decode($cached, true);

            if (is_array($decoded) && array_key_exists('body', $decoded)) {
                $response = new Response(
                    statusCode: is_int($decoded['status'] ?? null) ? $decoded['status'] : 200,
                    headers: is_array($decoded['headers'] ?? null) ? $decoded['headers'] : [],
                    body: (string) $decoded['body'],
                );

                return $response->withHeader('X-CMS-Cache', 'HIT');
            }
        }

        $response = $handler->handle($request);

        // Only cache successful HTML responses
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            $this->storeResponse($cacheKey, $response, $request);
        }

        return $response->withHeader('X-CMS-Cache', 'MISS');
    }

    private function shouldBypass(ServerRequestInterface $request): bool
    {
        $method = strtoupper($request->getMethod());

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return true;
        }

        $queryParams = $request->getQueryParams();

        if (array_key_exists('_nocache', $queryParams)) {
            return true;
        }

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity !== null && $identity->isAuthenticated()) {
            return array_any($identity->roles(), static fn(string $role): bool => str_starts_with($role, 'cms.'));
        }

        return false;
    }

    /** @var list<string> Query parameter prefixes excluded from the cache key. */
    private const array EXCLUDED_QUERY_PREFIXES = ['utm_', 'fbclid', 'gclid', 'msclkid'];

    private function computeCacheKey(ServerRequestInterface $request): string
    {
        /** @var mixed $rawTenantId */
        $rawTenantId = $request->getAttribute('tenant_id');
        $tenantId = is_string($rawTenantId) ? $rawTenantId : 'default';
        /** @var mixed $rawLocale */
        $rawLocale = $request->getAttribute('locale');
        $locale = is_string($rawLocale) ? $rawLocale : 'en';
        $path = ltrim($request->getUri()->getPath(), '/');

        // Include filtered, sorted query params in the cache key so different
        // query strings produce different cache entries. Marketing/tracking
        // parameters and the _nocache flag are excluded.
        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();
        $filtered = $this->filterQueryParams($queryParams);
        ksort($filtered);
        $queryHash = $filtered !== [] ? '?' . http_build_query($filtered) : '';

        return CmsCacheKeys::page($tenantId, $locale, hash('xxh3', $path . $queryHash));
    }

    /**
     * Filter out tracking and cache-bypass query parameters.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function filterQueryParams(array $params): array
    {
        $filtered = [];

        /** @var mixed $value */
        foreach ($params as $key => $value) {
            if ($key === '_nocache') {
                continue;
            }

            $excluded = false;

            foreach (self::EXCLUDED_QUERY_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $excluded = true;

                    break;
                }
            }

            if (!$excluded) {
                $filtered = [...$filtered, $key => $value];
            }
        }

        return $filtered;
    }

    private function storeResponse(string $cacheKey, ResponseInterface $response, ServerRequestInterface $request): void
    {
        $body = (string) $response->getBody();

        $serialized = json_encode([
            'body' => $body,
            'status' => $response->getStatusCode(),
            'headers' => $this->extractCacheableHeaders($response),
        ], JSON_THROW_ON_ERROR);

        $tags = $this->computeTags($request);

        $this->cache->set($cacheKey, $serialized, $tags, $this->cacheConfig->pageCacheTtlSeconds);
    }

    /**
     * @return array<string, string>
     */
    private function extractCacheableHeaders(ResponseInterface $response): array
    {
        $cacheableNames = ['Content-Type', 'Content-Language', 'Cache-Control'];
        $headers = [];

        foreach ($cacheableNames as $name) {
            $line = $response->getHeaderLine($name);

            if ($line !== '') {
                $headers[$name] = $line;
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function computeTags(ServerRequestInterface $request): array
    {
        $tags = [CmsCacheKeys::TAG_ALL_PAGES];

        /** @var string|null $contentId */
        $contentId = $request->getAttribute('cms_content_id');

        if ($contentId !== null) {
            $tags[] = CmsCacheKeys::contentTag($contentId);
        }

        /** @var string|null $contentType */
        $contentType = $request->getAttribute('cms_content_type');

        if ($contentType !== null) {
            $tags[] = CmsCacheKeys::typeTag($contentType);
        }

        /** @var list<string>|null $menuIds */
        $menuIds = $request->getAttribute('cms_menu_ids');

        if ($menuIds !== null) {
            foreach ($menuIds as $menuId) {
                $tags[] = CmsCacheKeys::menuTag($menuId);
            }
        }

        $tags[] = CmsCacheKeys::TAG_SETTINGS;

        return array_values(array_unique($tags));
    }
}
