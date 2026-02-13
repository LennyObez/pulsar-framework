<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function is_string;
use function preg_match;

/**
 * Extracts and validates the Bearer token from the Authorization header.
 *
 * On success, sets the `user_id` request attribute to the authenticated user's
 * identifier. On failure, returns a 401 JSON response.
 *
 * This is a simplified token guard for mobile API authentication. In production,
 * it should be replaced or extended with JWT validation, token introspection,
 * or integration with Pulsar's core auth module.
 */
#[Internal(reason: 'HTTP middleware — implementation detail')]
final readonly class SubscriptionTokenGuard implements MiddlewareInterface
{
    /**
     * @param callable(string): ?string $tokenResolver Resolves a Bearer token to a user ID.
     *                                                  Returns null if the token is invalid.
     */
    public function __construct(
        private mixed $tokenResolver,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '' || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return self::unauthorized('Missing or malformed Authorization header');
        }

        $token = $matches[1];
        $userId = ($this->tokenResolver)($token);

        if (!is_string($userId) || $userId === '') {
            return self::unauthorized('Invalid or expired token');
        }

        $authenticatedRequest = $request->withAttribute('user_id', $userId);

        return $handler->handle($authenticatedRequest);
    }

    private static function unauthorized(string $message): ResponseInterface
    {
        return Response::json(['error' => $message], 401);
    }
}
