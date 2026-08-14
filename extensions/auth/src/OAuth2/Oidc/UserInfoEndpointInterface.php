<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Internal;

/**
 * Resolves the claims a bearer token entitles its holder to.
 *
 * Internal, but named, for the same reason as the JWKS endpoint: the controller
 * must be able to name a contract rather than a final class. Claim resolution is
 * exactly the place a deployment adds its own — directory lookups, tenant
 * scoping, claim redaction under a retention policy — and none of that is
 * reachable when the only type available cannot be implemented.
 */
#[Internal(reason: 'Seam for the userinfo controller; not part of the public surface')]
interface UserInfoEndpointInterface
{
    /**
     * Resolve user claims from a bearer token.
     *
     * @param string $accessToken The bearer token value
     * @return array<string, mixed>|null Claims if token is valid, null otherwise
     */
    public function getClaims(string $accessToken): ?array;
}
