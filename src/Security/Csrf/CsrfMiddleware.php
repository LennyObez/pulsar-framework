<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CsrfConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

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
 * Returns a 403 Forbidden JSON response when validation fails.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CsrfTokenManagerInterface $tokenManager,
        private CsrfConfig $config,
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

        // Layer 1: Origin/Referer validation
        if ($this->config->originValidation !== 'off' && $this->config->trustedOrigins !== []) {
            $originResult = $this->validateOrigin($request);
            if ($originResult === false) {
                return $this->forbiddenResponse($request, 'Cross-origin request rejected');
            }
            if ($originResult === null && $this->config->originValidation === 'required') {
                return $this->forbiddenResponse($request, 'Origin header required for unsafe methods');
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
     * Extract the CSRF token from the request (header or POST field).
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // Try header first
        $headerToken = $request->getHeaderLine($this->config->headerName);

        if ($headerToken !== '') {
            return $headerToken;
        }

        // Fall back to POST field
        $parsedBody = $request->getParsedBody();
        $fieldToken = is_array($parsedBody) ? ($parsedBody[$this->config->formFieldName] ?? null) : null;

        if (is_string($fieldToken) && $fieldToken !== '') {
            return $fieldToken;
        }

        return null;
    }

    /**
     * Validate Origin (or Referer fallback) against configured trusted origins.
     *
     * @return bool|null true = matched, false = rejected, null = no header present
     */
    private function validateOrigin(ServerRequestInterface $request): ?bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') {
            return $this->originMatchesTrusted($origin);
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $refererOrigin = $this->extractOriginFromUrl($referer);
            if ($refererOrigin !== null) {
                return $this->originMatchesTrusted($refererOrigin);
            }
        }

        return null;
    }

    private function originMatchesTrusted(string $origin): bool
    {
        $origin = strtolower(rtrim(trim($origin), '/'));

        return array_any($this->config->trustedOrigins, function (string $trusted) use ($origin): bool {
            $trusted = strtolower(rtrim($trusted, '/'));

            return $origin === $trusted
                || $this->normalizePort($origin) === $this->normalizePort($trusted);
        });
    }

    private function normalizePort(string $origin): string
    {
        $origin = strtolower(trim($origin));
        $origin = preg_replace('#^https://([^/]+):443$#', 'https://$1', $origin) ?? $origin;
        return preg_replace('#^http://([^/]+):80$#', 'http://$1', $origin) ?? $origin;
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
     * Create a 403 Forbidden response, content-negotiated for the client.
     */
    private function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $accept = $request->getHeaderLine('Accept');

        if (str_contains($accept, 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
        ) {
            return Response::json(
                ['error' => 'Forbidden', 'message' => $message],
                ResponseStatus::Forbidden->value,
            );
        }

        $escapedMessage = htmlspecialchars($message);

        return Response::html(
            sprintf(
                '<!DOCTYPE html><html><head><title>403 Forbidden</title></head>'
                . '<body><h1>403 Forbidden</h1><p>%s</p></body></html>',
                $escapedMessage,
            ),
            ResponseStatus::Forbidden->value,
        );
    }
}
