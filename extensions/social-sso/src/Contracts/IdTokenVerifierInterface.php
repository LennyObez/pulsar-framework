<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\IdTokenVerificationContext;

/**
 * Verifies OpenID Connect ID tokens.
 *
 * Implementations validate the token signature, issuer, audience, expiration,
 * and other claims according to the OpenID Connect Core specification.
 */
#[Api(since: '1.0.0')]
interface IdTokenVerifierInterface
{
    /**
     * Verify an ID token and extract its claims.
     *
     * @throws \Pulsar\Extension\SocialSso\Exception\SsoException on verification failure
     */
    public function verify(string $idToken, IdTokenVerificationContext $context): IdTokenClaims;
}
