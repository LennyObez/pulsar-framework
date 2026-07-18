<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Closure;
use JsonException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\CsrfConfig;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function array_key_exists;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * CSRF protection middleware.
 *
 * Validates CSRF tokens on state-changing HTTP methods (POST, PUT, PATCH, DELETE).
 * Safe methods (GET, HEAD, OPTIONS) pass through without validation.
 *
 * Token is extracted from either:
 * - The configured header (default: `X-CSRF-Token`)
 * - The configured POST field (default: `_csrf_token`)
 *
 * Returns a 403 Forbidden response when validation fails, content-negotiated:
 * JSON for API clients, and — for browsers — HTML rendered by the application's
 * configured {@see ExceptionRendererInterface} (themed and localized like every
 * other 4xx) when one is wired, falling back to a minimal page otherwise. The
 * response is returned (not thrown) so it still flows through the outer
 * middleware (e.g. security headers) before reaching the client.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * @param (Closure(): ?ExceptionRendererInterface)|null $errorRendererResolver
     *     Lazy resolver for the error-page renderer, supplied by the composition
     *     root because the renderer is wired after this middleware. Invoked at
     *     request time to theme the 403 HTML page; null yields a minimal page.
     */
    public function __construct(
        private CsrfTokenManagerInterface $tokenManager,
        private CsrfConfig $config,
        private ?Closure $errorRendererResolver = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled) {
            return $handler->handle($request);
        }

        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        // Layer 1: cross-origin rejection (defense in depth). On by default —
        // it runs whenever origin_validation is not 'off', with NO dependency on
        // a configured allowlist: the expected origin is derived from the
        // request's own scheme+host (union any configured trusted_origins), so a
        // single-domain app is protected with zero configuration. An empty
        // trusted_origins therefore means "same-origin only", not "disabled".
        if ($this->config->originValidation !== 'off') {
            $verdict = $this->crossOriginVerdict($request);

            if ($verdict === self::ORIGIN_CROSS) {
                return $this->forbiddenResponse($request, 'Cross-origin request rejected');
            }

            // No verifiable browser signal at all (no Origin, no Sec-Fetch-Site,
            // no usable Referer). Modern browsers always send at least one on an
            // unsafe request, so this is reachable only by non-browser clients —
            // which carry no ambient cookies and so cannot mount CSRF. 'optional'
            // lets them through to the token check; 'required' refuses them.
            if ($verdict === self::ORIGIN_ABSENT && $this->config->originValidation === 'required') {
                return $this->forbiddenResponse($request, 'Origin could not be verified for an unsafe request');
            }
        }

        // Layer 2: Synchronizer token (always required)
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->forbiddenResponse($request, 'CSRF token is missing');
        }

        if (!$this->tokenManager->validate($token)) {
            return $this->forbiddenResponse($request, 'CSRF token is invalid');
        }

        return $handler->handle($request);
    }

    /**
     * F9.7: maximum JSON body bytes the middleware will rewind + parse to
     * extract a CSRF token. Headers are still the recommended carrier;
     * scanning the body is a fallback for JSON SPAs that ship the token
     * in `{"_csrf_token": "..."}`. 256 KiB is more than enough for any
     * realistic form-payload while bounding the cost of a hostile body.
     */
    private const int JSON_BODY_INSPECTION_LIMIT = 262_144;

    /**
     * Extract the CSRF token from the request (header, POST field, or JSON body).
     *
     * F9.7: for JSON-bodied requests no upstream middleware necessarily
     * parsed the body into `getParsedBody()`, so a SPA POSTing
     * `Content-Type: application/json` with `{"_csrf_token": "..."}` was
     * never matched by the form-field path and got rejected outright.
     * The middleware now reads + rewinds the body when the content type
     * is JSON and looks up the field in the decoded structure.
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // Try header first
        $headerToken = $request->getHeaderLine($this->config->headerName);

        if ($headerToken !== '') {
            return $headerToken;
        }

        // Form-encoded body (parsedBody is populated by PSR-7 itself
        // for `application/x-www-form-urlencoded` and `multipart/form-data`).
        $parsedBody = $request->getParsedBody();

        if (is_array($parsedBody) && array_key_exists($this->config->formFieldName, $parsedBody)) {
            /** @var mixed $fieldToken */
            $fieldToken = $parsedBody[$this->config->formFieldName];

            if (is_string($fieldToken) && $fieldToken !== '') {
                return $fieldToken;
            }
        }

        // JSON body fallback. Only inspected when the request advertises
        // `application/json`; we rewind the stream so the downstream
        // handler still sees the original body.
        $jsonToken = $this->extractJsonBodyToken($request);

        if ($jsonToken !== null) {
            return $jsonToken;
        }

        return null;
    }

    private function extractJsonBodyToken(ServerRequestInterface $request): ?string
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (!str_contains(strtolower($contentType), 'application/json')) {
            return null;
        }

        $body = $request->getBody();

        if (!$body->isReadable()) {
            return null;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $raw = $body->getContents();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($raw === '' || strlen($raw) > self::JSON_BODY_INSPECTION_LIMIT) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !array_key_exists($this->config->formFieldName, $decoded)) {
            return null;
        }

        /** @var mixed $field */
        $field = $decoded[$this->config->formFieldName];

        return is_string($field) && $field !== '' ? $field : null;
    }

    /** The request's origin matches an expected one (same-origin or trusted). */
    private const int ORIGIN_SAME = 0;

    /** A browser signal proves the request is cross-origin — reject. */
    private const int ORIGIN_CROSS = 1;

    /** No verifiable origin signal at all (non-browser client). */
    private const int ORIGIN_ABSENT = 2;

    /**
     * Classify a request's origin against the set the server accepts.
     *
     * Precedence follows signal reliability: an explicit Origin is authoritative;
     * `Sec-Fetch-Site` (sent by every evergreen browser) catches the case where
     * Origin is suppressed; a Referer-derived origin is the last positive signal.
     * `Origin: null` — which sandboxed iframes, `data:` navigations and some
     * redirect-laundering emit — is treated as CROSS, never as absent, because a
     * legitimate first-party request never sends it.
     */
    private function crossOriginVerdict(ServerRequestInterface $request): int
    {
        $expected = $this->expectedOrigins($request);

        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin !== '') {
            if (strtolower($origin) === 'null') {
                return self::ORIGIN_CROSS;
            }

            return $this->originIsExpected($origin, $expected) ? self::ORIGIN_SAME : self::ORIGIN_CROSS;
        }

        // Origin suppressed (e.g. a top-level form POST): Sec-Fetch-Site still
        // proves cross-site intent on every current browser.
        $fetchSite = strtolower(trim($request->getHeaderLine('Sec-Fetch-Site')));
        if ($fetchSite === 'cross-site' || $fetchSite === 'cross-origin') {
            return self::ORIGIN_CROSS;
        }
        if ($fetchSite === 'same-origin' || $fetchSite === 'same-site') {
            return self::ORIGIN_SAME;
        }

        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer !== '') {
            $refererOrigin = $this->extractOriginFromUrl($referer);
            if ($refererOrigin !== null) {
                return $this->originIsExpected($refererOrigin, $expected) ? self::ORIGIN_SAME : self::ORIGIN_CROSS;
            }
        }

        return self::ORIGIN_ABSENT;
    }

    /**
     * The origins the server accepts: its own (derived from the request's
     * scheme+host, so no configuration is required for a single-domain app)
     * unioned with any explicitly configured trusted origins.
     *
     * @return list<string> normalized origins
     */
    private function expectedOrigins(ServerRequestInterface $request): array
    {
        $expected = [];

        $self = $this->selfOrigin($request);
        if ($self !== null) {
            $expected[] = $self;
        }

        foreach ($this->config->trustedOrigins as $trusted) {
            $expected[] = $this->normalizeOrigin($trusted);
        }

        return $expected;
    }

    /**
     * The request's own origin (scheme://host[:non-default-port]). Behind a TLS
     * terminator that does not rewrite the request scheme, this can derive `http`
     * where the public origin is `https`; a configured `trusted_origins` entry is
     * the escape hatch for such deployments (documented in security-baseline.md).
     */
    private function selfOrigin(ServerRequestInterface $request): ?string
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());

        if ($scheme === '' || $host === '') {
            return null;
        }

        $origin = $scheme . '://' . $host;
        $port = $uri->getPort();
        if ($port !== null && !self::isDefaultPort($scheme, $port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    /**
     * @param list<string> $expected
     */
    private function originIsExpected(string $origin, array $expected): bool
    {
        $origin = $this->normalizeOrigin($origin);

        return in_array($origin, $expected, true);
    }

    private function normalizeOrigin(string $origin): string
    {
        $origin = strtolower(rtrim(trim($origin), '/'));
        $origin = preg_replace('#^(https://[^/:]+):443$#', '$1', $origin) ?? $origin;

        return preg_replace('#^(http://[^/:]+):80$#', '$1', $origin) ?? $origin;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }

    private function extractOriginFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin;
    }

    /**
     * Build a 403 Forbidden response, content-negotiated for the client.
     *
     * JSON clients (Accept: application/json or X-Requested-With: XMLHttpRequest)
     * get a JSON body. Browsers get HTML rendered by the configured error-page
     * renderer — themed and localized like every other 4xx — when one is wired;
     * otherwise a minimal inline page. The response is returned (not thrown) so
     * the outer middleware (e.g. security headers) still applies to it.
     */
    private function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
        ) {
            return Response::json(
                ['error' => 'Forbidden', 'message' => $message],
                ResponseStatus::Forbidden->value,
            );
        }

        $renderer = $this->errorRendererResolver !== null ? ($this->errorRendererResolver)() : null;

        if ($renderer instanceof ExceptionRendererInterface) {
            return Response::html(
                $renderer->render(HttpException::forbidden($message), $request, ResponseStatus::Forbidden),
                ResponseStatus::Forbidden->value,
            );
        }

        return Response::html(
            sprintf(
                '<!DOCTYPE html><html><head><title>403 Forbidden</title></head>'
                . '<body><h1>403 Forbidden</h1><p>%s</p></body></html>',
                htmlspecialchars($message),
            ),
            ResponseStatus::Forbidden->value,
        );
    }
}
