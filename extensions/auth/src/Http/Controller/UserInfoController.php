<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Oidc\UserInfoEndpointInterface;
use Pulsar\Http\Message\Response;

use function preg_match;
use function trim;

/**
 * Route handler for the OIDC UserInfo endpoint (OIDC Core §5.3).
 *
 * Only registered when the application has bound a
 * {@see \Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface}:
 * the claims come from the host's user store, which the extension cannot
 * supply, so without that binding the endpoint does not exist rather than
 * failing at dispatch.
 */
#[Internal(reason: 'Route handler')]
final readonly class UserInfoController
{
    public function __construct(
        private UserInfoEndpointInterface $endpoint,
    ) {}

    public function __invoke(ServerRequestInterface $request): Response
    {
        $token = self::bearerToken($request->getHeaderLine('Authorization'));

        if ($token === null) {
            // RFC 6750 §3.1: an error code belongs on a request that actually
            // presented a credential, so a request carrying none — or one whose
            // Authorization header is not a well-formed bearer credential —
            // gets the bare challenge.
            return Response::json(['error' => 'invalid_token'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $claims = $this->endpoint->getClaims($token);

        if ($claims === null) {
            return Response::json(['error' => 'invalid_token'], 401)
                ->withHeader(
                    'WWW-Authenticate',
                    'Bearer error="invalid_token", error_description="The access token is expired or revoked"',
                );
        }

        return Response::json($claims);
    }

    /**
     * Extract the credential from an `Authorization: Bearer <token>` header.
     *
     * The scheme is case-insensitive per RFC 9110 §11.1; the token itself is
     * not, so it is returned verbatim.
     */
    private static function bearerToken(string $header): ?string
    {
        if (preg_match('/^Bearer[ \t]+(?<token>[A-Za-z0-9\-._~+\/]+=*)[ \t]*$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches['token'];
    }
}
