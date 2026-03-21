<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use JsonException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\CsrfConfig;
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
 * Returns a 403 Forbidden JSON response when validation fails.
 * @api
 */
#[Api(since: '1.0.0')]
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
