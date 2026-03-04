<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Domain;

use Pulsar\Api\Api;

/**
 * Aggregate result of a complete SSO login flow.
 *
 * Combines the resolved social identity, the identity linking
 * outcome, and optionally verified ID token claims into a
 * single result object for downstream processing.
 */
#[Api(since: '1.0.0')]
final readonly class SsoLoginResult
{
    /**
     * @param SocialIdentity $socialIdentity          Normalized identity from the OAuth provider
     * @param LinkedIdentityResult $linkResult        Result of the identity linking step
     * @param ?IdTokenClaims $verifiedClaims          Verified OIDC ID token claims (null if not an OIDC flow)
     */
    public function __construct(
        public SocialIdentity $socialIdentity,
        public LinkedIdentityResult $linkResult,
        public ?IdTokenClaims $verifiedClaims = null,
    ) {}
}
