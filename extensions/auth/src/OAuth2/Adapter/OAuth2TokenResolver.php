<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;

use function is_string;

/**
 * OAuth2 bearer token resolver for the auth guard system.
 *
 * Resolves OAuth2 access tokens (reference or JWT) to Pulsar identities.
 * Integrates with the existing TokenGuard via TokenResolverInterface.
 */
#[Internal(reason: 'Adapter implementation; use TokenResolverInterface')]
final readonly class OAuth2TokenResolver implements TokenResolverInterface
{
    public function __construct(
        private AccessTokenRepositoryInterface $tokenRepository,
        private UserClaimsProviderInterface $claimsProvider,
    ) {}

    #[Override]
    public function resolve(string $token): ?IdentityInterface
    {
        $accessToken = $this->tokenRepository->introspect($token);

        if ($accessToken === null || !$accessToken->isActive()) {
            return null;
        }

        // Resolve basic profile claims for the identity
        $claims = $this->claimsProvider->getClaims($accessToken->subjectId, $accessToken->scopes);

        return new Identity(
            id: $accessToken->subjectId,
            displayName: is_string($claims['name'] ?? null) ? $claims['name'] : (is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : $accessToken->subjectId),
            roles: [],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [
                'oauth2_client_id' => $accessToken->clientId,
                'oauth2_scopes' => $accessToken->scopes,
                'oauth2_token_id' => $accessToken->id,
            ],
        );
    }
}
