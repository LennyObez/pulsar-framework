<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Checks the redirect table before content resolution.
 *
 * If a matching redirect is found for the current path, returns an
 * HTTP redirect response (301 or 308) and increments the hit counter.
 */
#[Internal(reason: 'CMS middleware — not a public API surface')]
final readonly class CmsSlugRedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RedirectRepositoryInterface $redirectRepository,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = ltrim($request->getUri()->getPath(), '/');

        if ($path === '') {
            return $handler->handle($request);
        }

        // Extract locale from path if present (e.g., "fr/about" → locale=fr, path=about)
        $locale = null;
        $lookupPath = $path;
        if (preg_match('#^([a-z]{2}(?:-[A-Z]{2})?)(/.*)?$#', $path, $matches) === 1) {
            $locale = $matches[1];
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $redirect = $this->redirectRepository->findByPath($lookupPath, $locale, $tenantId);

        if ($redirect === null && $locale !== null) {
            // Try without locale prefix — the path may match a global redirect
            $redirect = $this->redirectRepository->findByPath($lookupPath, null, $tenantId);
        }

        if ($redirect === null) {
            return $handler->handle($request);
        }

        // Validate redirect status code — only 301 (permanent) and 308 (permanent, method-preserving)
        $statusCode = $redirect->statusCode;

        if ($statusCode !== 301 && $statusCode !== 308) {
            $statusCode = 301;
        }

        // Increment hit counter (fire-and-forget — don't block the response)
        $this->redirectRepository->incrementHits($redirect->id);

        $targetUrl = $redirect->toPath;

        // If the target is a relative path, normalize to a root-relative URL
        if (!str_starts_with($targetUrl, 'http://') && !str_starts_with($targetUrl, 'https://')) {
            $targetUrl = '/' . ltrim($targetUrl, '/');
        } else {
            // Absolute URL — validate that the host matches the current request to prevent open redirects
            $targetHost = parse_url($targetUrl, PHP_URL_HOST);
            $requestHost = $request->getUri()->getHost();

            if ($targetHost !== false && $targetHost !== null && strtolower($targetHost) !== strtolower($requestHost)) {
                // External redirect blocked — fall through to next handler
                return $handler->handle($request);
            }
        }

        $allowedHosts = [];
        $requestHost = $request->getUri()->getHost();

        if ($requestHost !== '') {
            $allowedHosts[] = $requestHost;
        }

        return Response::redirect($targetUrl, $statusCode, $allowedHosts);
    }
}
