<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

use function in_array;
use function preg_replace;
use function rtrim;

/**
 * Permanently redirects a request whose path is not the canonical form.
 *
 * The router's {@see \Pulsar\Routing\Route::matchesPath()} trims leading and
 * trailing slashes before matching, so a single registered route silently
 * answers an unbounded family of paths — `/x`, `/x/`, `//x`, `///x///` all
 * match and return 200. That is duplicate content: every crawlable URL exists
 * under infinitely many spellings, splitting crawl budget and link equity.
 *
 * When enabled (opt-in via `routing.redirect_to_canonical_path`), this
 * middleware runs OUTERMOST — before locale-prefix stripping — collapses any
 * run of slashes to one, drops a trailing slash, and issues a permanent
 * redirect to that single canonical spelling. The root "/" is exempt.
 *
 * The redirect target is always origin-form (a path beginning with exactly one
 * "/"): collapsing every slash run to one makes a protocol-relative "//evil.com"
 * spelling impossible to emit, and {@see \Pulsar\Http\SafeRedirect} (invoked by
 * {@see Response::redirect()}) rejects it as a second line of defence, so a
 * slash variant can never be turned into an open redirect.
 */
#[Internal]
final readonly class CanonicalPathMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $canonical = $this->canonicalize($path);

        if ($canonical === $path) {
            return $handler->handle($request);
        }

        $target = $canonical;
        $query = $uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        // Safe, idempotent methods get 301 (cacheable, the SEO-canonical
        // signal crawlers honour). Non-safe methods get 308, which preserves
        // the method and body so a POST to a non-canonical path is not silently
        // downgraded to a GET the way a 301/302 would allow.
        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return Response::redirect($target, $status);
    }

    /**
     * Collapse repeated slashes to one and strip a trailing slash. The root
     * path "/" (and an empty path) is returned unchanged — it is canonical by
     * definition, so it never triggers a redirect loop.
     */
    private function canonicalize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }

        $collapsed = preg_replace('#/{2,}#', '/', $path);
        // preg_replace only returns null on a malformed pattern, which this
        // constant literal is not; the guard keeps the return type honest.
        if ($collapsed === null) {
            return $path;
        }

        if ($collapsed === '/') {
            return '/';
        }

        $trimmed = rtrim($collapsed, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
