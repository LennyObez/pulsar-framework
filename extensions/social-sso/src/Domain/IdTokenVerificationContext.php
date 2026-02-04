<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Domain;

use Pulsar\Api\Api;

/**
 * Context parameters for verifying an OIDC ID token.
 *
 * Provides the expected audience, issuer, nonce, and acceptable clock
 * skew when validating ID token claims and signature.
 */
#[Api(since: '1.0.0')]
final readonly class IdTokenVerificationContext
{
    /**
     * @param string $clientId             Expected audience (client ID)
     * @param string $issuer               Expected issuer URL
     * @param ?string $nonce               Expected nonce (must match if present in token)
     * @param int $maxClockSkewSeconds     Maximum tolerable clock skew for exp/iat checks
     */
    public function __construct(
        public string $clientId,
        public string $issuer,
        public ?string $nonce = null,
        public int $maxClockSkewSeconds = 120,
    ) {}
}
