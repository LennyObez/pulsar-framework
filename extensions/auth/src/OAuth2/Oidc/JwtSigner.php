<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\KeyRingInterface;
use RuntimeException;

use function base64_encode;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_sign;
use function openssl_verify;
use function rtrim;
use function str_replace;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;

/**
 * JWT signing using RS256 (RSA + SHA-256) for OIDC ID tokens.
 *
 * OIDC ID tokens MUST use asymmetric signing (OIDC Core §3.1.3.7).
 * Key material is sourced from the framework KeyRing (kid -> RSA private
 * key PEM), not from configuration, so rotation goes through the same
 * KeyRing-managed lifecycle as every other key in the framework.
 */
#[Internal(reason: 'Implementation detail; adapter for JOSE library')]
final readonly class JwtSigner implements JwtSignerInterface
{
    public function __construct(
        private KeyRingInterface $keyRing,
        private OidcConfig $config,
    ) {}

    #[Override]
    public function sign(array $claims, string $keyId): string
    {
        $keyMaterial = $this->keyRing->keyFor($keyId);

        if ($keyMaterial === null) {
            throw new RuntimeException("Signing key '$keyId' not found in Keyring");
        }

        $privateKey = openssl_pkey_get_private($keyMaterial);

        if ($privateKey === false) {
            throw new RuntimeException("Keyring entry '$keyId' is not a valid RSA private key PEM");
        }

        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $keyId,
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $signingInput = $headerEncoded . '.' . $payloadEncoded;

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signed) {
            throw new RuntimeException('RSA signing failed');
        }

        /** @var string $signature */
        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    #[Override]
    public function verify(string $jwt, string $keyId, ?string $expectedAudience = null): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $keyMaterial = $this->keyRing->keyFor($keyId);

        if ($keyMaterial === null) {
            return null;
        }

        $privateKey = openssl_pkey_get_private($keyMaterial);

        if ($privateKey === false) {
            return null;
        }

        $keyDetails = openssl_pkey_get_details($privateKey);

        if ($keyDetails === false || !isset($keyDetails['key']) || !is_string($keyDetails['key'])) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($keyDetails['key']);

        if ($publicKey === false) {
            return null;
        }

        $signingInput = $headerEncoded . '.' . $payloadEncoded;
        $actualSignature = self::base64UrlDecode($signatureEncoded);

        $valid = openssl_verify($signingInput, $actualSignature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($valid !== 1) {
            return null;
        }

        $payload = self::base64UrlDecode($payloadEncoded);
        /** @var array<string, mixed>|null $claims */
        $claims = json_decode($payload, true);

        if (!is_array($claims)) {
            return null;
        }

        if (!$this->validateClaims($claims, $expectedAudience)) {
            return null;
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims, ?string $expectedAudience): bool
    {
        $now = time();

        // exp (RFC 7519 §4.1.4): reject expired tokens.
        if (isset($claims['exp'])) {
            $expRaw = $claims['exp'];

            if (!is_int($expRaw) && !(is_string($expRaw) && is_numeric($expRaw))) {
                return false;
            }

            if ($now >= (int) $expRaw) {
                return false;
            }
        }

        // nbf (RFC 7519 §4.1.5): reject not-yet-valid tokens.
        if (isset($claims['nbf'])) {
            $nbfRaw = $claims['nbf'];

            if (!is_int($nbfRaw) && !(is_string($nbfRaw) && is_numeric($nbfRaw))) {
                return false;
            }

            if ($now < (int) $nbfRaw) {
                return false;
            }
        }

        // iss: must match configured issuer when both sides are present.
        if ($this->config->issuer !== '' && isset($claims['iss'])) {
            if (!is_string($claims['iss']) || $claims['iss'] !== $this->config->issuer) {
                return false;
            }
        }

        // aud (RFC 7519 §4.1.3): a JWT is bound to the recipients named in its
        // `aud` claim, and "each principal intended to process the JWT MUST
        // identify itself with a value in the audience claim". The recipient
        // is supplied by the caller via $expectedAudience (e.g. an OAuth
        // client_id for an ID token, or the OP's own identifier for a
        // self-issued token) — JwtSigner is the OP and does not have a single
        // fixed audience of its own. When an expected audience is given and the
        // token carries an `aud`, that audience MUST be present, else the token
        // is rejected (anti confused-deputy / token-redirect). When no expected
        // audience is supplied, `aud` is not constrained here: the relying party
        // validates it against its own client_id.
        if ($expectedAudience !== null && isset($claims['aud'])) {
            $aud = $claims['aud'];

            if (is_string($aud)) {
                if ($aud !== $expectedAudience) {
                    return false;
                }
            } elseif (is_array($aud)) {
                if (!in_array($expectedAudience, $aud, true)) {
                    return false;
                }
            } else {
                // Present but neither string nor array of strings → malformed.
                return false;
            }
        }

        return true;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($data)), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_replace(['-', '_'], ['+', '/'], $data);
        $decoded = base64_decode($padded, true);

        return $decoded !== false ? $decoded : '';
    }
}
