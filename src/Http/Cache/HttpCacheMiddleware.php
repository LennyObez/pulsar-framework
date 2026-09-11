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
 * ## This is a SHARED cache, and it refuses anything that carries identity
 *
 * The stored entry is keyed by method, path and query — nothing else — and is
 * handed to whoever asks for that key next. So an entry produced for one caller
 * is an entry served to every other caller, and the only safe rule is that no
 * entry may ever be produced for a caller in the first place. The middleware
 * therefore does not consult the store and does not write to it when:
 *
 *   - the request carries a `Cookie`, `Authorization` or `Proxy-Authorization`
 *     header — a credential, a session, or anything a handler can personalise on;
 *   - the response carries `Set-Cookie` — a session, CSRF token or consent value
 *     minted for the one caller that asked;
 *   - the response declares `Cache-Control: private`, `no-store` or `no-cache`;
 *   - the response declares `Vary` — it varies on a request dimension this key
 *     does not contain, so the key cannot tell the variants apart;
 *   - the response declares `Transfer-Encoding` — a stored entry is replayed as
 *     a plain buffered response, and a transfer coding replayed in front of raw
 *     bytes is a desync;
 *   - the route declares `cache.private`, which asks for a per-user answer this
 *     middleware has no per-user store to give.
 *
 * Refusing rather than keying by identity is deliberate. An identity-keyed
 * shared cache multiplies the blast radius of any future key defect by the
 * number of users; refusing does not, and a refusal costs a cache miss.
 *
 * Configure per-route via request attributes:
 *   - `cache.ttl` (int): TTL in seconds (default: 60)
 *   - `cache.tags` (list<string>): Tags for invalidation groups
 *   - `cache.private` (bool): Set `Cache-Control: private` and keep the response
 *     out of the shared store entirely — the browser may cache it, this
 *     middleware does not
 *   - `cache.enabled` (bool): Set to false to skip caching for this route
 *   - `cache.share_across_clients` (bool): Assert that this route's response is
 *     the same for every caller, so a request carrying identity may still be
 *     served from and stored in the shared cache. The response-side refusals
 *     above still apply and still win: an assertion cannot make a response
 *     carrying `Set-Cookie` or `Cache-Control: private` shareable.
 *
 * `X-Cache` reports what happened: `HIT` served from the store, `MISS` produced
 * and stored, `BYPASS` refused — the store was neither read nor written.
 * @api
 */
#[Api(since: '1.0.0')]
final class HttpCacheMiddleware implements MiddlewareInterface
{
    /**
     * Request headers that make a request one caller's request.
     *
     * `Cookie` covers the session cookie without having to know its name, and
     * covers every other per-client value a handler may personalise on. Guessing
     * which cookies are "just analytics" is how a shared cache ends up serving
     * one account's page to another.
     *
     * @var list<string>
     */
    private const array IDENTITY_REQUEST_HEADERS = [
        'Cookie',
        'Authorization',
        'Proxy-Authorization',
    ];

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

        $isPrivate = (bool) $request->getAttribute('cache.private', false);

        // A caller-bound request never touches the shared store, in either
        // direction. Not reading it is as load-bearing as not writing it: a
        // stored anonymous page served to a signed-in caller is the same class of
        // defect seen from the other side, and this middleware cannot tell which
        // of the two a given hit would be.
        if ($isPrivate || $this->carriesIdentity($request)) {
            $response = $handler->handle($request)->withHeader('X-Cache', 'BYPASS');

            // The route asked for `private`, and it still gets it: the BROWSER
            // may keep this response for its own user. Only the shared store is
            // refused, because there is no per-user shared store to put it in.
            //
            // A directive the response already carries is left alone. This runs
            // before the response has been examined, and overwriting a handler's
            // `no-store` with `private, max-age=N` would tell the browser to keep
            // what the origin just said must be kept nowhere.
            if ($isPrivate && !$response->hasHeader('Cache-Control')) {
                $response = $response->withHeader(
                    'Cache-Control',
                    $this->buildCacheControl($this->resolveTtl($request), true),
                );
            }

            return $response;
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

        // The response itself gets the last word on whether it may be shared,
        // and it gets it AFTER the handler ran — so an application that decides
        // per request is never overruled by a route-level assertion made before
        // the answer existed.
        if ($this->responseRefusesSharing($response)) {
            return $response->withHeader('X-Cache', 'BYPASS');
        }

        $ttl = $this->resolveTtl($request);
        $tags = $this->resolveTags($request);

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

        // Add cache headers to the live response. `public` is not a guess here:
        // everything that could have made this response one caller's has already
        // sent it out through a BYPASS, so what remains is shareable by
        // construction — which is exactly what downstream proxies are being told.
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $this->buildCacheControl($ttl, false))
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

    /**
     * Whether this request belongs to one caller.
     *
     * `cache.share_across_clients` is the application asserting that the route's
     * answer does not depend on the caller — a public page an authenticated
     * browser happens to request. The assertion only reaches THIS test: it
     * cannot make a response that DECLARES itself one caller's shareable,
     * because {@see responseRefusesSharing()} is evaluated afterwards on the
     * real response and is not conditioned on it.
     *
     * That bound is worth stating exactly, because it is narrower than it looks.
     * The response-side refusals read headers only; this middleware
     * does not inspect the body. A page that renders the caller's name, sets no
     * cookie and declares no `Vary` passes every one of them. The assertion is a
     * promise the middleware cannot verify — it is only as good as the route it
     * is put on, and on a personalised route it produces exactly the
     * cross-caller disclosure the refusals exist to prevent.
     */
    private function carriesIdentity(ServerRequestInterface $request): bool
    {
        if ($request->getAttribute('cache.share_across_clients') === true) {
            return false;
        }

        foreach (self::IDENTITY_REQUEST_HEADERS as $header) {
            if (trim($request->getHeaderLine($header)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the produced response may not be handed to a different caller.
     */
    private function responseRefusesSharing(ResponseInterface $response): bool
    {
        // A cookie the handler just minted belongs to the one caller it was
        // minted for. Storing the response stores the cookie with it, and every
        // later requester of that key is handed someone else's session, CSRF
        // token or consent record.
        if (trim($response->getHeaderLine('Set-Cookie')) !== '') {
            return true;
        }

        // `Vary` names request dimensions the response depends on. The key
        // contains method, path and query and nothing else, so it cannot tell
        // two variants apart — including the two that matter most,
        // `Vary: Cookie` and `Vary: Authorization`.
        if (trim($response->getHeaderLine('Vary')) !== '') {
            return true;
        }

        // A stored entry is replayed as a plain, fully buffered response. A
        // transfer coding stored alongside it would describe a framing the
        // replay does not use — `Transfer-Encoding: chunked` in front of raw
        // bytes, which is a desync, not a slow page. The live response that
        // declared it is emitted correctly; only a HIT would be malformed, and
        // the way not to serve a malformed HIT is not to store one.
        if (trim($response->getHeaderLine('Transfer-Encoding')) !== '') {
            return true;
        }

        // Respect no-store / no-cache / private directives from the origin.
        // Use str_contains() to match the directive anywhere in the header value,
        // since Cache-Control can contain multiple comma-separated directives
        // (e.g. "private, no-store, max-age=0").
        $cacheControl = $response->getHeaderLine('Cache-Control');

        return str_contains($cacheControl, 'no-store')
            || str_contains($cacheControl, 'no-cache')
            || str_contains($cacheControl, 'private');
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
