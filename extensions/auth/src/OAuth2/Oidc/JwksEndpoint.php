<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\KeyRingInterface;

use function base64_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function rtrim;
use function str_replace;

use const OPENSSL_KEYTYPE_RSA;

/**
 * JWKS endpoint handler.
 *
 * Serves the JSON Web Key Set at /.well-known/jwks.json containing the
 * public keys used for ID token verification.
 *
 * SEC-OIDC-01 / SEC-OIDC-02: keys are extracted from KeyRing-managed RSA
 * private keys (PEM) and published as RFC 7517 JWK objects with kty=RSA,
 * alg=RS256, and base64url-encoded modulus (n) and exponent (e). The
 * earlier homegrown HS256 output exposed a symmetric-key descriptor that
 * did not match the actual signer (RS256 via openssl_sign), breaking
 * OIDC clients that rely on JWKS for key discovery.
 */
#[Internal(reason: 'Implementation detail')]
final readonly class JwksEndpoint implements JwksEndpointInterface
{
    public function __construct(
        private KeyRingInterface $keyRing,
    ) {}

    /**
     * Generate the JWKS document.
     *
     * Only RSA keys are published; entries that cannot be parsed as RSA
     * private keys (e.g. HMAC bytes left over from a pre-RS256 keyring)
     * are skipped so that a single bad entry cannot poison discovery.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwksDocument(): array
    {
        $keys = [];

        foreach ($this->keyRing->all() as $kid => $keyBytes) {
            $privateKey = @openssl_pkey_get_private($keyBytes);

            if ($privateKey === false) {
                continue;
            }

            $details = openssl_pkey_get_details($privateKey);

            if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                continue;
            }

            /** @var array{n: string, e: string} $rsa */
            $rsa = $details['rsa'] ?? null;

            if (!isset($rsa['n'], $rsa['e'])) {
                continue;
            }

            $keys[] = [
                'kty' => 'RSA',
                'kid' => (string) $kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64UrlEncode($rsa['n']),
                'e' => self::base64UrlEncode($rsa['e']),
            ];
        }

        return ['keys' => $keys];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($data)), '=');
    }
}
