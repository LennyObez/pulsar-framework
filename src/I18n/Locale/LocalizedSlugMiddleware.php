<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function in_array;
use function is_string;

/**
 * Rewrites localized URL slugs to canonical route keys before routing.
 *
 * Runs immediately after {@see LocalePrefixMiddleware}, which has already
 * stripped the locale prefix and recorded the active locale in the `_locale`
 * request attribute. Using the active locale, this middleware:
 *
 *  - rewrites a recognised localized slug (e.g. `/developpement/projets/x`
 *    under `fr`) to its canonical key path (`/development/projects/x`) so the
 *    locale-agnostic router matches the route registered under the key, with
 *    any trailing route parameters preserved; or
 *  - issues a configurable 301 to the canonical slug when a request uses a
 *    non-canonical alias (e.g. the bare key `/fr/development` → 301
 *    `/fr/developpement`), GET/HEAD only, query string preserved; or
 *  - passes the request through untouched when no slug applies.
 *
 * Resolution is constant-time array probing against the precompiled
 * {@see SlugRegistry}; there is no per-request parsing or database access.
 */
#[Internal]
final readonly class LocalizedSlugMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SlugRegistry $registry,
        private I18nConfig $config,
        private UrlPrefixExtractor $extractor,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->registry->isEmpty()) {
            return $handler->handle($request);
        }

        $locale = $this->resolveLocale($request);
        $path = $request->getUri()->getPath();

        $match = $this->registry->matchLocalized($locale, $path);

        if ($match === null) {
            return $handler->handle($request);
        }

        if (!$match->isCanonical && $this->shouldRedirect($request)) {
            return $this->redirectToCanonical($request, $match, $locale);
        }

        $keyPath = $this->joinPath($match->key, $match->remainder);
        $uri = $request->getUri()->withPath($keyPath);

        return $handler->handle($request->withUri($uri));
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        /** @var mixed $locale */
        $locale = $request->getAttribute('_locale');

        return is_string($locale) && $locale !== '' ? $locale : $this->config->defaultLocale;
    }

    private function shouldRedirect(ServerRequestInterface $request): bool
    {
        return $this->config->canonicalRedirect
            && in_array($request->getMethod(), ['GET', 'HEAD'], true);
    }

    private function redirectToCanonical(
        ServerRequestInterface $request,
        SlugMatch $match,
        string $locale,
    ): ResponseInterface {
        $canonicalPath = $this->joinPath($this->registry->slugFor($match->key, $locale), $match->remainder);

        $target = $this->extractor->buildPath(
            $canonicalPath,
            $locale,
            $this->config->defaultLocale,
            $this->config->defaultLocaleInUrl,
        );

        $query = $request->getUri()->getQuery();

        if ($query !== '') {
            $target .= '?' . $query;
        }

        return Response::redirect($target, 301);
    }

    /**
     * Join a slug/key with its trailing remainder into an absolute path.
     */
    private function joinPath(string $base, string $remainder): string
    {
        $path = '/' . $base;

        if ($remainder !== '') {
            $path .= '/' . $remainder;
        }

        return $path;
    }
}
