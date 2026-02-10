<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\KeyRingInterface;
use RuntimeException;

use function base64_encode;
use function count;
use function json_encode;
use function rtrim;
use function str_replace;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * JWT signing using Keyring-managed keys.
 *
 * Signs JWT payloads using HMAC-SHA256 with Keyring-managed symmetric keys.
 * For RSA/EC signing with the JOSE library, this class would be replaced
 * by a JoseLibrarySigner adapter. This implementation provides a working
 * baseline using symmetric signing.
 */
#[Internal(reason: 'Implementation detail — adapter for JOSE library')]
final readonly class JwtSigner
{
    public function __construct(
        private KeyRingInterface $keyRing,
    ) {}

    /**
     * Sign claims as a JWT.
     *
     * @param array<string, mixed> $claims The JWT payload claims
     * @param string $keyId The Keyring key identifier for signing
     * @return string The signed JWT string (header.payload.signature)
     */
    public function sign(array $claims, string $keyId): string
    {
        $key = $this->keyRing->keyFor($keyId);

        if ($key === null) {
            throw new RuntimeException("Signing key '$keyId' not found in Keyring");
        }

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
            'kid' => $keyId,
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $signingInput = $headerEncoded . '.' . $payloadEncoded;
        $signature = hash_hmac('sha256', $signingInput, $key, true);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Verify a JWT signature.
     *
     * @param string $jwt The JWT string to verify
     * @param string $keyId The Keyring key identifier for verification
     * @return array<string, mixed>|null The decoded claims, or null if verification fails
     */
    public function verify(string $jwt, string $keyId): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $key = $this->keyRing->keyFor($keyId);

        if ($key === null) {
            return null;
        }

        $signingInput = $headerEncoded . '.' . $payloadEncoded;
        $expectedSignature = hash_hmac('sha256', $signingInput, $key, true);
        $actualSignature = self::base64UrlDecode($signatureEncoded);

        if (!hash_equals($expectedSignature, $actualSignature)) {
            return null;
        }

        $payload = self::base64UrlDecode($payloadEncoded);
        /** @var array<string, mixed>|null $claims */
        $claims = json_decode($payload, true);

        return $claims;
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
