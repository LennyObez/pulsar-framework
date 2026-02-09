<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use function htmlspecialchars;
use function is_string;

use Override;

use function parse_url;
use function preg_replace;

use Pulsar\Config\CsrfConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function rtrim;
use function sprintf;
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
    public function process(Request $request, callable $next): Response
    {
        if (!$this->config->enabled) {
            return $next($request);
        }

        if ($request->method->isSafe()) {
            return $next($request);
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

        return $next($request);
    }

    /**
     * Extract the CSRF token from the request (header or POST field).
     */
    private function extractToken(Request $request): ?string
    {
        // Try header first
        $headerToken = $request->header($this->config->headerName);

        if ($headerToken !== null && $headerToken !== '') {
            return $headerToken;
        }

        // Fall back to POST field
        $fieldToken = $request->post($this->config->formFieldName);

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
    private function validateOrigin(Request $request): ?bool
    {
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            return $this->originMatchesTrusted($origin);
        }

        $referer = $request->header('Referer');
        if ($referer !== null) {
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

        foreach ($this->config->trustedOrigins as $trusted) {
            $trusted = strtolower(rtrim($trusted, '/'));
            if ($origin === $trusted) {
                return true;
            }
            if ($this->normalizePort($origin) === $this->normalizePort($trusted)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePort(string $origin): string
    {
        $origin = strtolower(trim($origin));
        $origin = preg_replace('#^https://([^/]+):443$#', 'https://$1', $origin) ?? $origin;
        $origin = preg_replace('#^http://([^/]+):80$#', 'http://$1', $origin) ?? $origin;
        return $origin;
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
    private function forbiddenResponse(Request $request, string $message): Response
    {
        if ($request->wantsJson() || $request->isAjax()) {
            return Response::json(
                ['error' => 'Forbidden', 'message' => $message],
                ResponseStatus::Forbidden,
            );
        }

        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(
            sprintf(
                '<!DOCTYPE html><html><head><title>403 Forbidden</title></head>'
                . '<body><h1>403 Forbidden</h1><p>%s</p></body></html>',
                $escapedMessage,
            ),
            ResponseStatus::Forbidden,
        );
    }
}
