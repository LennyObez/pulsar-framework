<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use Pulsar\Api\Internal;
use RuntimeException;

use function base64_encode;
use function count;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function rtrim;
use function str_replace;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;

/**
 * JWT signing using RS256 (RSA + SHA-256) for OIDC ID tokens.
 *
 * OIDC ID tokens MUST use asymmetric signing. This implementation uses
 * RS256 via OpenSSL, which is the mandatory-to-implement algorithm per
 * the OpenID Connect Core specification (Section 3.1.3.7).
 */
#[Internal(reason: 'Implementation detail; adapter for JOSE library')]
final readonly class JwtSigner
{
    public function __construct(
        private OidcConfig $config,
    ) {}

    /**
     * Sign claims as a JWT using RS256.
     *
     * @param array<string, mixed> $claims The JWT payload claims
     * @param string $keyId The key identifier for the JWT header (kid)
     * @return string The signed JWT string (header.payload.signature)
     */
    public function sign(array $claims, string $keyId): string
    {
        $privateKey = openssl_pkey_get_private($this->config->signingKey);

        if ($privateKey === false) {
            throw new RuntimeException('Invalid RSA private key in OidcConfig::signingKey');
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

    /**
     * Verify a JWT signature using the public key derived from the configured private key.
     *
     * @param string $jwt The JWT string to verify
     * @return array<string, mixed>|null The decoded claims, or null if verification fails
     */
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $privateKey = openssl_pkey_get_private($this->config->signingKey);

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

        if ($claims === null) {
            return null;
        }

        // Validate standard JWT claims
        if (!$this->validateClaims($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Validate standard JWT claims: exp, nbf, iss, aud.
     *
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims): bool
    {
        $now = time();

        // Reject expired tokens. RFC 7519 §4.1.4 mandates a NumericDate
        // (seconds since epoch). Anything that is not int|numeric-string
        // is malformed and the token must be rejected.
        if (isset($claims['exp'])) {
            $expRaw = $claims['exp'];

            if (!is_int($expRaw) && !(is_string($expRaw) && is_numeric($expRaw))) {
                return false;
            }

            if ($now >= (int) $expRaw) {
                return false;
            }
        }

        // Reject tokens not yet valid (RFC 7519 §4.1.5).
        if (isset($claims['nbf'])) {
            $nbfRaw = $claims['nbf'];

            if (!is_int($nbfRaw) && !(is_string($nbfRaw) && is_numeric($nbfRaw))) {
                return false;
            }

            if ($now < (int) $nbfRaw) {
                return false;
            }
        }

        // Validate issuer matches configured issuer
        if ($this->config->issuer !== '' && isset($claims['iss'])) {
            if (!is_string($claims['iss']) || $claims['iss'] !== $this->config->issuer) {
                return false;
            }
        }

        // Audience (aud) is intentionally NOT validated here. OIDC Core
        // 3.1.3.7 specifies that the *relying party* validates aud
        // against its own client_id; the issuing OIDC server (this
        // class) does not know which RP a re-introspected token was
        // minted for. Earlier revisions tried to assert
        // `aud === iss` ("OIDC self-issued tokens"), which rejected
        // every regular ID token where aud is the client_id and not
        // the issuer URL. Audience validation, if needed, belongs in
        // the calling code that knows the expected audience.

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
