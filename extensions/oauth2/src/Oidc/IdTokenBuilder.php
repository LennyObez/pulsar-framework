<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\OAuth2\Contract\UserClaimsProviderInterface;

/**
 * Builds and signs OIDC ID tokens.
 *
 * ID tokens are JWTs containing the required OIDC claims (iss, sub, aud, exp, iat, nonce)
 * plus any additional claims resolved from the UserClaimsProvider based on granted scopes.
 *
 * All signing operations use Keyring-managed keys (Finding B).
 * JOSE operations are handled via the web-token/jwt-framework library adapter.
 */
#[Internal(reason: 'Implementation detail — consumers use TokenIssuerInterface')]
final readonly class IdTokenBuilder
{
    public function __construct(
        private OidcConfig $config,
        private UserClaimsProviderInterface $claimsProvider,
        private JwtSigner $jwtSigner,
    ) {}

    /**
     * Build and sign an ID token.
     *
     * @param string $subjectId The resource owner identifier
     * @param string $clientId The OAuth2 client (audience)
     * @param list<string> $scopes Granted scopes
     * @param string|null $nonce The nonce from the authorization request
     * @param int $ttl Token lifetime in seconds
     * @return string The signed JWT string
     */
    public function build(
        string $subjectId,
        string $clientId,
        array $scopes,
        ?string $nonce = null,
        int $ttl = 900,
    ): string {
        $now = new DateTimeImmutable();

        // Required OIDC claims
        $claims = [
            'iss' => $this->config->issuer,
            'sub' => $this->claimsProvider->getSubjectIdentifier($subjectId, $clientId),
            'aud' => $clientId,
            'exp' => $now->getTimestamp() + $ttl,
            'iat' => $now->getTimestamp(),
        ];

        if ($nonce !== null) {
            $claims['nonce'] = $nonce;
        }

        // Merge scope-derived claims from the claims provider
        $userClaims = $this->claimsProvider->getClaims($subjectId, $scopes);
        $claims = array_merge($claims, $userClaims);

        return $this->jwtSigner->sign($claims, $this->config->signingKeyId);
    }
}
