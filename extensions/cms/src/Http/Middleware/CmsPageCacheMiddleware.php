<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Tenancy\Tenant;
use Random\Engine\Secure;
use Random\IntervalBoundary;
use Random\Randomizer;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function hash;
use function hrtime;
use function http_build_query;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function log;
use function max;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtoupper;
use function substr;
use function time;
use function trim;

/**
 * Full-page cache for public CMS content with stampede protection.
 *
 * Serving model (all knobs from {@see CmsCacheConfig}):
 *
 * - FRESH hit: served from cache. With stampede protection, XFetch
 *   probabilistic early recomputation (Vattani/Chierichetti/Lowenstein, VLDB
 *   2015) elects the occasional request to regenerate BEFORE expiry under a
 *   non-blocking lock, so a hot page never expires for everyone at once —
 *   losers keep serving the fresh copy.
 * - STALE hit (past logical expiry, within `stale_grace_period_seconds`): one
 *   non-blocking lock winner regenerates inline; everyone else serves the
 *   stale copy immediately — nobody ever waits on a page that has a copy.
 * - COLD miss: single-flight — one blocking lock winner (up to
 *   `lock_timeout_seconds`) renders while others wait, double-check, and serve
 *   the winner's copy; on lock timeout the request renders without the lock
 *   (fail-open, a user never gets an error because of the cache).
 *
 * Cache key: tenant + locale stay readable; host, path, and the filtered
 * canonical query string are digested with SHA-256 (collision-resistant — the
 * previous xxh3 allowed offline-forgeable collisions, i.e. cache poisoning).
 * Tenant resolves from the CMS `tenant_id` attribute, then the framework
 * `_tenant` attribute, then 'default'; locale from `cms_locale` (set by
 * CmsLocaleMiddleware, which MUST run before this middleware), then `_locale`.
 *
 * Never served from or written to the shared cache: non-GET/HEAD requests, any
 * authenticated identity, requests carrying the session cookie, responses that
 * set cookies, non-200 or non-HTML responses, responses marked
 * `Cache-Control: no-store|private`, and bodies carrying per-user security
 * material (CSRF form/meta markers, CSP nonce attributes) — a shared cache
 * must never replay one visitor's tokens to another. Only GET responses are
 * stored (a HEAD-warmed body may legitimately be empty); HEAD is served from
 * cache and the kernel emitter strips the body per RFC 9110.
 *
 * Tags: the content pipeline declares fine-grained tags through the internal
 * {@see self::TAGS_HEADER} response header, which this middleware consumes and
 * always strips before the response leaves the server. Every entry also
 * carries the coarse cms_pages/cms_settings floor tags so broad invalidation
 * works even when no fine-grained tag was declared. A write is abandoned when
 * the CMS invalidation epoch changed while rendering, or when the lock fence
 * cannot be refreshed — so a publish that lands mid-render cannot be pinned
 * over by a stale body for the remaining TTL.
 */
#[Internal(reason: 'CMS middleware; not a public API surface')]
final readonly class CmsPageCacheMiddleware implements MiddlewareInterface
{
    /**
     * Internal response header carrying comma-separated cache tags from the
     * content pipeline to this middleware. Stripped from every response.
     */
    public const string TAGS_HEADER = 'X-Pulsar-Cms-Cache-Tags';

    /** Stored envelope schema version. */
    private const int ENVELOPE_VERSION = 2;

    /** @var list<string> Query parameter prefixes excluded from the cache key. */
    private const array EXCLUDED_QUERY_PREFIXES = ['utm_', 'fbclid', 'gclid', 'msclkid'];

    /**
     * @var list<string> Case-insensitive body markers of per-user security
     *     material. A page containing any of them is never stored: replaying a
     *     CSRF token or CSP nonce cross-user breaks both. Conservative false
     *     positives only make a page uncached, never wrong.
     */
    private const array UNCACHEABLE_BODY_MARKERS = ['name="_csrf', 'csrf-token', 'nonce="'];

    /** Lock TTL: an upper bound on a single page render. */
    private const int LOCK_TTL_SECONDS = 30;

    private Randomizer $randomizer;

    public function __construct(
        private TaggedCacheInterface $cache,
        private CmsCacheConfig $cacheConfig,
        private ?LockInterface $lock = null,
        private ?string $sessionCookieName = null,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldBypass($request)) {
            // The tags header is server-internal: strip it on every path out.
            return $handler->handle($request)->withoutHeader(self::TAGS_HEADER);
        }

        $cacheKey = $this->computeCacheKey($request);
        $envelope = $this->readEnvelope($cacheKey);
        $now = time();

        if ($envelope !== null) {
            if ($now < $envelope['expiresAt']) {
                if ($this->stampedeEnabled() && $this->shouldRecomputeEarly($envelope, $now)) {
                    $handle = $this->tryLock($cacheKey);

                    if ($handle !== null) {
                        // Elected early-recompute winner: pay the render now so
                        // the entry never expires under load; losers keep
                        // serving the still-fresh copy below.
                        return $this->regenerate($request, $handler, $cacheKey, $handle);
                    }
                }

                return $this->serveCached($envelope, $now, 'HIT');
            }

            // Logically expired but physically retained (grace window).
            if ($this->stampedeEnabled()) {
                $handle = $this->tryLock($cacheKey);

                if ($handle !== null) {
                    return $this->regenerate($request, $handler, $cacheKey, $handle);
                }

                // Someone else is regenerating: serve stale immediately —
                // never block a request that has a copy in hand.
                return $this->serveCached($envelope, $now, 'STALE');
            }

            return $this->regenerate($request, $handler, $cacheKey, null);
        }

        // Cold miss: single-flight when stampede protection is on.
        $lock = $this->lock;

        if ($this->cacheConfig->stampedeProtection && $lock !== null) {
            try {
                $handle = $lock->acquire(
                    $this->lockResource($cacheKey),
                    self::LOCK_TTL_SECONDS,
                    $this->cacheConfig->lockTimeoutSeconds * 1000,
                );
            } catch (Throwable) {
                // Lock wait timed out: the winner is probably still rendering.
                // Re-check once (it may have finished), then render without
                // the lock rather than fail or wait again.
                $retry = $this->readEnvelope($cacheKey);

                if ($retry !== null) {
                    return $this->serveCached($retry, $now, $now < $retry['expiresAt'] ? 'HIT' : 'STALE');
                }

                return $this->regenerate($request, $handler, $cacheKey, null);
            }

            // Double-check: the winner may have written while we waited.
            $retry = $this->readEnvelope($cacheKey);

            if ($retry !== null && $now < $retry['expiresAt']) {
                $lock->release($handle);

                return $this->serveCached($retry, $now, 'HIT');
            }

            return $this->regenerate($request, $handler, $cacheKey, $handle);
        }

        return $this->regenerate($request, $handler, $cacheKey, null);
    }

    /**
     * Render downstream, then store the response when it is shareable and no
     * invalidation raced the render.
     */
    private function regenerate(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $cacheKey,
        ?LockHandle $handle,
    ): ResponseInterface {
        try {
            /** @var mixed $epochBefore */
            $epochBefore = $this->cache->get(CmsCacheKeys::INVALIDATION_EPOCH_KEY);

            $start = hrtime(true);
            $response = $handler->handle($request);
            $deltaMs = (hrtime(true) - $start) / 1_000_000.0;

            $tags = $this->extractTags($response);
            $response = $response->withoutHeader(self::TAGS_HEADER);

            if ($this->isStorable($request, $response)) {
                /** @var mixed $epochAfter */
                $epochAfter = $this->cache->get(CmsCacheKeys::INVALIDATION_EPOCH_KEY);

                // Abandon the write if (a) a CMS invalidation landed while we
                // rendered — our body may predate the change and would pin
                // stale content under fresh tag versions for a full TTL — or
                // (b) our lock fence expired, meaning another winner may
                // already have written a newer body.
                $fenceHolds = $handle === null || $this->lock === null || $this->lock->refresh($handle, self::LOCK_TTL_SECONDS);

                if ($epochBefore === $epochAfter && $fenceHolds) {
                    $this->store($cacheKey, $response, $deltaMs, $tags);
                }
            }

            return $response->withHeader('X-CMS-Cache', 'MISS');
        } finally {
            if ($handle !== null) {
                $this->lock?->release($handle);
            }
        }
    }

    /**
     * @param array{v: int, body: string, status: int, headers: array<string, string>, createdAt: int, delta: float, expiresAt: int} $envelope
     */
    private function serveCached(array $envelope, int $now, string $state): ResponseInterface
    {
        return new Response(
            statusCode: $envelope['status'],
            headers: $envelope['headers'],
            body: $envelope['body'],
        )
            ->withHeader('X-CMS-Cache', $state)
            ->withHeader('Age', (string) max(0, $now - $envelope['createdAt']));
    }

    /**
     * @param list<string> $tags
     */
    private function store(string $cacheKey, ResponseInterface $response, float $deltaMs, array $tags): void
    {
        $now = time();
        $physicalTtl = $this->cacheConfig->pageCacheTtlSeconds
            + ($this->stampedeEnabled() ? $this->cacheConfig->staleGracePeriodSeconds : 0);

        $this->cache->set($cacheKey, [
            'v' => self::ENVELOPE_VERSION,
            'body' => (string) $response->getBody(),
            'status' => $response->getStatusCode(),
            'headers' => $this->extractCacheableHeaders($response),
            'createdAt' => $now,
            'delta' => $deltaMs,
            'expiresAt' => $now + $this->cacheConfig->pageCacheTtlSeconds,
        ], $tags, $physicalTtl);
    }

    /**
     * @return array{v: int, body: string, status: int, headers: array<string, string>, createdAt: int, delta: float, expiresAt: int}|null
     */
    private function readEnvelope(string $cacheKey): ?array
    {
        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);

        if (!is_array($cached)
            || ($cached['v'] ?? null) !== self::ENVELOPE_VERSION
            || !is_string($cached['body'] ?? null)
            || !is_int($cached['status'] ?? null)
            || !is_array($cached['headers'] ?? null)
            || !is_int($cached['createdAt'] ?? null)
            || !(is_float($cached['delta'] ?? null) || is_int($cached['delta'] ?? null))
            || !is_int($cached['expiresAt'] ?? null)
        ) {
            return null;
        }

        /** @var array{v: int, body: string, status: int, headers: array<string, string>, createdAt: int, delta: float|int, expiresAt: int} $cached */
        return [
            'v' => $cached['v'],
            'body' => $cached['body'],
            'status' => $cached['status'],
            'headers' => $cached['headers'],
            'createdAt' => $cached['createdAt'],
            'delta' => (float) $cached['delta'],
            'expiresAt' => $cached['expiresAt'],
        ];
    }

    /**
     * XFetch: recompute early iff now − delta·beta·ln(U) ≥ expiry, with U drawn
     * from (0,1] — U=1 means "never early", U→0 advances the horizon; delta is
     * the measured render cost, so expensive pages start regenerating earlier.
     * ln(0) would be −INF (recompute on every request), hence the open lower
     * bound.
     *
     * @param array{v: int, body: string, status: int, headers: array<string, string>, createdAt: int, delta: float, expiresAt: int} $envelope
     */
    private function shouldRecomputeEarly(array $envelope, int $now): bool
    {
        if ($envelope['delta'] <= 0.0 || $this->cacheConfig->earlyRecomputeBeta <= 0.0) {
            return false;
        }

        $u = $this->randomizer->getFloat(0.0, 1.0, IntervalBoundary::OpenClosed);

        return $now - ($envelope['delta'] / 1000.0) * $this->cacheConfig->earlyRecomputeBeta * log($u)
            >= $envelope['expiresAt'];
    }

    private function stampedeEnabled(): bool
    {
        return $this->cacheConfig->stampedeProtection && $this->lock !== null;
    }

    private function tryLock(string $cacheKey): ?LockHandle
    {
        try {
            return $this->lock?->acquire($this->lockResource($cacheKey), self::LOCK_TTL_SECONDS, 0);
        } catch (Throwable) {
            return null;
        }
    }

    private function lockResource(string $cacheKey): string
    {
        return 'cms_page_lock.' . substr($cacheKey, strlen(CmsCacheKeys::PAGE_KEY_PREFIX));
    }

    private function shouldBypass(ServerRequestInterface $request): bool
    {
        $method = strtoupper($request->getMethod());

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return true;
        }

        // ANY authenticated identity bypasses the shared cache — their pages
        // may be personalised, and an authenticated response must never become
        // another visitor's page. (This also gives editors an implicit
        // fresh-view; the old anonymous `_nocache` bypass was a free public
        // cache-busting lever and is gone — the parameter is still excluded
        // from the key, so it cannot mint variants either.)
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity !== null && $identity->isAuthenticated()) {
            return true;
        }

        // A session cookie means server-side per-visitor state (a cart, a
        // stateful CSRF session) even without an authenticated identity.
        if ($this->sessionCookieName !== null
            && array_key_exists($this->sessionCookieName, $request->getCookieParams())
        ) {
            return true;
        }

        return false;
    }

    /**
     * Whether the response may enter the shared cache. Everything here is a
     * one-way gate: failing it only means the page is served uncached.
     */
    private function isStorable(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        // Only GET renders are stored: HEAD shares the key and is served from
        // cache, but a HEAD-warmed body may legitimately be empty and would
        // blank the page for every subsequent GET.
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (!str_starts_with($response->getHeaderLine('Content-Type'), 'text/html')) {
            return false;
        }

        // A Set-Cookie response is per-visitor by definition.
        if ($response->getHeader('Set-Cookie') !== []) {
            return false;
        }

        $cacheControl = $response->getHeaderLine('Cache-Control');

        if (stripos($cacheControl, 'no-store') !== false || stripos($cacheControl, 'private') !== false) {
            return false;
        }

        // Per-user security material must never be replayed cross-user: a
        // cached CSRF token breaks (or worse, unifies) token binding, and a
        // frozen CSP nonce no longer matches the fresh CSP header.
        $body = (string) $response->getBody();

        foreach (self::UNCACHEABLE_BODY_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return false;
            }
        }

        return true;
    }

    private function computeCacheKey(ServerRequestInterface $request): string
    {
        // Tenant: the CMS convention attribute first, then the framework
        // tenancy attribute set by TenantResolutionMiddleware, then 'default'.
        /** @var mixed $rawTenantId */
        $rawTenantId = $request->getAttribute('tenant_id');
        /** @var mixed $tenant */
        $tenant = $request->getAttribute('_tenant');

        $tenantId = is_string($rawTenantId) && $rawTenantId !== ''
            ? $rawTenantId
            : ($tenant instanceof Tenant ? $tenant->id : 'default');

        // Locale: CmsLocaleMiddleware runs immediately before this middleware
        // on the content routes and sets cms_locale; the core locale attribute
        // is the fallback for stripped-prefix setups.
        /** @var mixed $cmsLocale */
        $cmsLocale = $request->getAttribute('cms_locale');
        /** @var mixed $coreLocale */
        $coreLocale = $request->getAttribute('_locale');

        $locale = is_string($cmsLocale) && $cmsLocale !== ''
            ? $cmsLocale
            : (is_string($coreLocale) && $coreLocale !== '' ? $coreLocale : 'default');

        // The host is part of the page's identity: canonical URLs, hreflang,
        // and og:url reflect it, and multi-domain deployments must never share
        // entries across hosts. SHA-256 (not xxh3) so a collision cannot be
        // forged offline to poison another page's entry.
        $host = $request->getUri()->getHost();
        $path = $request->getUri()->getPath();

        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();
        $filtered = $this->filterQueryParams($queryParams);
        ksort($filtered);
        $canonicalQuery = $filtered !== [] ? http_build_query($filtered) : '';

        $digest = hash('sha256', $host . "\0" . $path . "\0" . $canonicalQuery);

        return CmsCacheKeys::page($tenantId, $locale, $digest);
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
                if (str_starts_with((string) $key, $prefix)) {
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

    /**
     * Fine-grained tags declared by the content pipeline via the internal
     * response header, merged with the coarse floor tags so broad invalidation
     * always reaches every page entry.
     *
     * @return list<string>
     */
    private function extractTags(ResponseInterface $response): array
    {
        $declared = [];

        $headerLine = $response->getHeaderLine(self::TAGS_HEADER);

        if ($headerLine !== '') {
            $declared = array_values(array_filter(
                array_map(static fn(string $tag): string => trim($tag), explode(',', $headerLine)),
                static fn(string $tag): bool => $tag !== '',
            ));
        }

        return array_values(array_unique(array_merge(
            [CmsCacheKeys::TAG_ALL_PAGES],
            $declared,
            [CmsCacheKeys::TAG_SETTINGS],
        )));
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
}
