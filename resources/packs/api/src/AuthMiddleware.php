<?php

declare(strict_types=1);

namespace {{namespace}}\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;

/**
 * API authentication middleware stub.
 *
 * Validates API credentials from the request. Replace the authentication
 * logic with your actual implementation (API key validation, JWT verification, etc.).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private const string API_KEY_HEADER = 'X-API-Key';
    private const string BEARER_HEADER = 'Authorization';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip auth for health check and other public endpoints
        $publicPaths = ['/health', '/api/v1/health'];

        if (in_array($request->getUri()->getPath(), $publicPaths, true)) {
            return $handler->handle($request);
        }

        // Try API key authentication
        $apiKey = $request->getHeaderLine(self::API_KEY_HEADER);

        if ($apiKey !== '') {
            if (!$this->validateApiKey($apiKey)) {
                return self::unauthorized('Invalid API key.');
            }

            return $handler->handle($request);
        }

        // Try Bearer token authentication
        $authorization = $request->getHeaderLine(self::BEARER_HEADER);

        if (str_starts_with($authorization, 'Bearer ')) {
            $token = substr($authorization, 7);

            if (!$this->validateBearerToken($token)) {
                return self::unauthorized('Invalid or expired token.');
            }

            return $handler->handle($request);
        }

        return self::unauthorized('Authentication required. Provide an API key or Bearer token.');
    }

    /**
     * Validate an API key. Replace with actual validation logic.
     *
     * QUAL-TODO-01 (external audit): the starter pack used to return
     * `$apiKey !== ''`, which looked like a real check and was easy to
     * ship to production by accident. Fail-closed by throwing instead —
     * a developer who copies this template gets a clear runtime error
     * pointing them at the wiring instead of a silent auth bypass.
     */
    private function validateApiKey(string $apiKey): bool
    {
        throw new \LogicException(
            'AuthMiddleware::validateApiKey is a starter-pack placeholder. '
            . 'Wire your real validation against your key store (database, cache, '
            . 'or remote introspection) before deploying this middleware.',
        );
    }

    /**
     * Validate a Bearer token. Replace with actual JWT/token validation.
     *
     * QUAL-TODO-02: same fail-closed contract as validateApiKey above.
     */
    private function validateBearerToken(string $token): bool
    {
        throw new \LogicException(
            'AuthMiddleware::validateBearerToken is a starter-pack placeholder. '
            . 'Wire your real validation (JWT signature + expiry check, OAuth2 '
            . 'introspection, opaque-token store lookup) before deploying this '
            . 'middleware.',
        );
    }

    private static function unauthorized(string $message): ResponseInterface
    {
        return Response::json([
            'error' => 'unauthorized',
            'message' => $message,
        ], 401);
    }
}
