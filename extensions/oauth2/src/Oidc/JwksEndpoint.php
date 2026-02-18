<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\KeyRingInterface;

/**
 * JWKS endpoint handler.
 *
 * Serves the JSON Web Key Set at /.well-known/jwks.json containing
 * the public keys used for ID token verification.
 *
 * Public keys are derived from Keyring-managed signing keys.
 */
#[Internal(reason: 'Implementation detail')]
final readonly class JwksEndpoint
{
    public function __construct(
        private KeyRingInterface $keyRing,
    ) {}

    /**
     * Generate the JWKS document.
     *
     * For HMAC-based signing (HS256), the JWKS contains symmetric key references.
     * For RSA/EC signing (RS256/ES256) via JOSE library, this would serve public keys.
     *
     * @return array<string, mixed>
     */
    public function jwksDocument(): array
    {
        $keys = [];

        foreach ($this->keyRing->all() as $kid => $keyBytes) {
            // For symmetric keys (HS256), we expose only the key ID and algorithm
            // The actual key material is never exposed in the JWKS
            // For asymmetric keys (RS256/ES256), this would extract the public key
            $keys[] = [
                'kty' => 'oct',
                'kid' => $kid,
                'use' => 'sig',
                'alg' => 'HS256',
            ];
        }

        return ['keys' => $keys];
    }
}
