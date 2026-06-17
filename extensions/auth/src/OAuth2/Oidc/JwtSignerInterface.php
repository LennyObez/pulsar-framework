<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Api;

/**
 * Contract for JWT signing and verification.
 * @api
 */
#[Api(since: '1.0.0')]
interface JwtSignerInterface
{
    /**
     * Sign claims as a JWT.
     *
     * @param array<string, mixed> $claims The JWT payload claims
     * @param string $keyId The key identifier for signing
     * @return string The signed JWT string
     */
    public function sign(array $claims, string $keyId): string;

    /**
     * Verify a JWT signature and claims.
     *
     * @param string $jwt The JWT string to verify
     * @param string $keyId The key identifier for verification
     * @param string|null $expectedAudience When provided, the token's `aud` claim
     *        (if present) must contain this value (RFC 7519 §4.1.3); when null,
     *        `aud` is not constrained here and is validated by the relying party.
     * @return array<string, mixed>|null The decoded claims, or null if verification fails
     */
    public function verify(string $jwt, string $keyId, ?string $expectedAudience = null): ?array;
}
