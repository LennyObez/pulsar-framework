<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;

/**
 * OIDC UserInfo endpoint handler.
 *
 * Returns claims about the authenticated end-user based on the
 * access token's granted scopes. Per OIDC Core 5.3.
 */
#[Internal(reason: 'Implementation detail')]
final readonly class UserInfoEndpoint
{
    public function __construct(
        private UserClaimsProviderInterface $claimsProvider,
        private AccessTokenRepositoryInterface $tokenRepository,
    ) {}

    /**
     * Resolve user claims from a bearer token.
     *
     * @param string $accessToken The bearer token value
     * @return array<string, mixed>|null Claims if token is valid, null otherwise
     */
    public function getClaims(string $accessToken): ?array
    {
        $token = $this->tokenRepository->introspect($accessToken);

        if ($token === null || !$token->isActive()) {
            return null;
        }

        $claims = $this->claimsProvider->getClaims($token->subjectId, $token->scopes);

        // UserInfo MUST include the sub claim
        $claims['sub'] = $this->claimsProvider->getSubjectIdentifier(
            $token->subjectId,
            $token->clientId,
        );

        return $claims;
    }
}
