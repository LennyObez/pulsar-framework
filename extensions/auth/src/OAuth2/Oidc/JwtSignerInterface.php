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
     * Verify a JWT signature.
     *
     * @param string $jwt The JWT string to verify
     * @param string $keyId The key identifier for verification
     * @return array<string, mixed>|null The decoded claims, or null if verification fails
     */
    public function verify(string $jwt, string $keyId): ?array;
}
