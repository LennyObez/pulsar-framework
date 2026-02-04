<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Features\ExchangeCode;

use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;

/**
 * Result DTO for an OAuth authorization code exchange.
 *
 * Contains the token set and optionally the verified OIDC ID token claims.
 */
final readonly class ExchangeCodeResult
{
    public function __construct(
        public OAuthTokenSet $tokenSet,
        public ?IdTokenClaims $verifiedClaims = null,
    ) {}
}
