<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Token;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Contracts\JwtSignatureDriverInterface;
use Pulsar\Extension\SocialSso\Domain\JwkKey;

use function chr;
use function in_array;
use function openssl_verify;
use function ord;
use function strlen;
use function substr;

use const OPENSSL_ALGO_SHA256;

/**
 * OpenSSL-based JWT signature verification driver.
 *
 * Supports RS256 (RSA PKCS#1 v1.5 with SHA-256) and ES256 (ECDSA P-256 with SHA-256).
 * For ES256, converts the raw R+S concatenated signature to DER format before verification.
 */
#[Internal]
final readonly class OpenSslJwtSignatureDriver implements JwtSignatureDriverInterface
{
    private const array SUPPORTED_ALGORITHMS = ['RS256', 'ES256'];
    private const int ES256_COMPONENT_LENGTH = 32;

    #[Override]
    public function supports(string $algorithm): bool
    {
        return in_array($algorithm, self::SUPPORTED_ALGORITHMS, true);
    }

    #[Override]
    public function verify(string $header, string $payload, string $signature, JwkKey $key): bool
    {
        $pem = $key->getPublicKeyPem();

        if ($pem === null) {
            return false;
        }

        $data = $header . '.' . $payload;
        $alg = $key->alg ?? '';

        return match ($alg) {
            'RS256' => $this->verifyRs256($data, $signature, $pem),
            'ES256' => $this->verifyEs256($data, $signature, $pem),
            default => false,
        };
    }

    /**
     * Verify an RS256 signature using OpenSSL.
     */
    private function verifyRs256(string $data, string $signature, string $pem): bool
    {
        $result = openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * Verify an ES256 signature using OpenSSL.
     *
     * ECDSA JWT signatures use a raw R+S format (64 bytes for P-256),
     * which must be converted to DER-encoded ASN.1 for OpenSSL.
     */
    private function verifyEs256(string $data, string $signature, string $pem): bool
    {
        $componentLength = self::ES256_COMPONENT_LENGTH;

        if (strlen($signature) !== $componentLength * 2) {
            return false;
        }

        $r = substr($signature, 0, $componentLength);
        $s = substr($signature, $componentLength);

        $derSignature = self::ecRawToDer($r, $s);

        $result = openssl_verify($data, $derSignature, $pem, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * Convert raw R and S integers to DER-encoded ASN.1 SEQUENCE of INTEGERs.
     *
     * Strips leading zero bytes but prepends 0x00 if the high bit is set
     * (to ensure unsigned integer encoding in ASN.1).
     */
    private static function ecRawToDer(string $r, string $s): string
    {
        $r = self::trimLeadingZeros($r);
        $s = self::trimLeadingZeros($s);

        // Ensure unsigned integer encoding
        if (ord($r[0]) > 0x7f) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) > 0x7f) {
            $s = "\x00" . $s;
        }

        $rAsn = "\x02" . chr(strlen($r)) . $r;
        $sAsn = "\x02" . chr(strlen($s)) . $s;
        $sequence = $rAsn . $sAsn;

        return "\x30" . chr(strlen($sequence)) . $sequence;
    }

    /**
     * Strip leading zero bytes from an integer, preserving at least one byte.
     */
    private static function trimLeadingZeros(string $data): string
    {
        $length = strlen($data);

        for ($i = 0; $i < $length - 1; $i++) {
            if (ord($data[$i]) !== 0) {
                break;
            }
        }

        return substr($data, $i);
    }
}
