<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Domain;

use Pulsar\Api\Api;

/**
 * Verified OIDC ID token claims.
 *
 * Contains the standard claims extracted from a verified ID token JWT.
 * Additional provider-specific claims are available via the $claims array.
 */
#[Api(since: '1.0.0')]
final readonly class IdTokenClaims
{
    /**
     * @param string $sub            Subject identifier (unique user ID at the issuer)
     * @param string $iss            Issuer identifier URL
     * @param string|list<string> $aud Audience (client ID or list of client IDs)
     * @param int $exp               Expiration timestamp (Unix epoch)
     * @param int $iat               Issued-at timestamp (Unix epoch)
     * @param ?string $nonce         Nonce echoed from the authorization request
     * @param ?string $azp           Authorized party (client ID when multiple audiences)
     * @param array<string, mixed> $claims  Additional claims beyond the standard set
     */
    public function __construct(
        public string $sub,
        public string $iss,
        public string|array $aud,
        public int $exp,
        public int $iat,
        public ?string $nonce = null,
        public ?string $azp = null,
        public array $claims = [],
    ) {}
}
