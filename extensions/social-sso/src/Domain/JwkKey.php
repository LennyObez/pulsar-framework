<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Domain;

use Pulsar\Api\Api;

use function array_key_exists;
use function base64_decode;
use function chr;
use function openssl_pkey_get_public;
use function pack;
use function str_replace;
use function strlen;

/**
 * Immutable JWK (JSON Web Key) representation.
 *
 * Holds the key type, optional metadata, and raw key parameters.
 * Supports conversion to PEM format for RSA and EC public keys
 * via the getPublicKeyPem() method.
 */
#[Api(since: '1.0.0')]
final readonly class JwkKey
{
    /**
     * @param string $kty                  Key type ("RSA", "EC", "oct", etc.)
     * @param ?string $kid                 Key ID for matching against JWT headers
     * @param ?string $alg                 Algorithm hint (e.g., "RS256", "ES256")
     * @param ?string $use                 Key usage ("sig" or "enc")
     * @param array<string, mixed> $parameters  Raw JWK parameters (n, e, x, y, crv, etc.)
     */
    public function __construct(
        public string $kty,
        public ?string $kid = null,
        public ?string $alg = null,
        public ?string $use = null,
        public array $parameters = [],
    ) {}

    /**
     * Convert the JWK to a PEM-encoded public key string.
     *
     * Supports RSA keys (requires "n" and "e" parameters) and EC keys
     * (requires "crv", "x", and "y" parameters). Returns null for
     * unsupported key types or missing parameters.
     */
    public function getPublicKeyPem(): ?string
    {
        return match ($this->kty) {
            'RSA' => $this->rsaToPem(),
            'EC' => $this->ecToPem(),
            default => null,
        };
    }

    /**
     * Build a PEM-encoded RSA public key from JWK "n" and "e" parameters.
     */
    private function rsaToPem(): ?string
    {
        if (!array_key_exists('n', $this->parameters) || !array_key_exists('e', $this->parameters)) {
            return null;
        }

        /** @var string $n */
        $n = $this->parameters['n'];
        /** @var string $e */
        $e = $this->parameters['e'];

        $modulus = self::base64UrlDecode($n);
        $exponent = self::base64UrlDecode($e);

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        // Ensure unsigned integer encoding (prepend 0x00 if high bit set)
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }
        if (ord($exponent[0]) > 0x7f) {
            $exponent = "\x00" . $exponent;
        }

        $modulusAsn = self::asn1Integer($modulus);
        $exponentAsn = self::asn1Integer($exponent);

        $pubKeySequence = self::asn1Sequence($modulusAsn . $exponentAsn);
        $pubKeyBitString = self::asn1BitString($pubKeySequence);

        // RSA OID: 1.2.840.113549.1.1.1
        $algorithmIdentifier = self::asn1Sequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00",
        );

        $der = self::asn1Sequence($algorithmIdentifier . $pubKeyBitString);
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----";

        // Validate the generated PEM
        $key = openssl_pkey_get_public($pem);

        return $key !== false ? $pem : null;
    }

    /**
     * Build a PEM-encoded EC public key from JWK "crv", "x", and "y" parameters.
     */
    private function ecToPem(): ?string
    {
        if (
            !array_key_exists('crv', $this->parameters)
            || !array_key_exists('x', $this->parameters)
            || !array_key_exists('y', $this->parameters)
        ) {
            return null;
        }

        /** @var string $crv */
        $crv = $this->parameters['crv'];
        /** @var string $x */
        $x = $this->parameters['x'];
        /** @var string $y */
        $y = $this->parameters['y'];

        $xBytes = self::base64UrlDecode($x);
        $yBytes = self::base64UrlDecode($y);

        if ($xBytes === '' || $yBytes === '') {
            return null;
        }

        $curveOid = self::ecCurveOid($crv);
        if ($curveOid === null) {
            return null;
        }

        // Uncompressed EC point: 0x04 || x || y
        $point = "\x04" . $xBytes . $yBytes;

        // ecPublicKey OID: 1.2.840.10045.2.1
        $algorithmIdentifier = self::asn1Sequence(
            "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . $curveOid,
        );

        $pubKeyBitString = self::asn1BitString($point);
        $der = self::asn1Sequence($algorithmIdentifier . $pubKeyBitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----";

        $key = openssl_pkey_get_public($pem);

        return $key !== false ? $pem : null;
    }

    /**
     * Get the ASN.1 DER-encoded OID for a named EC curve.
     */
    private static function ecCurveOid(string $crv): ?string
    {
        return match ($crv) {
            'P-256' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'P-384' => "\x06\x05\x2b\x81\x04\x00\x22",
            'P-521' => "\x06\x05\x2b\x81\x04\x00\x23",
            default => null,
        };
    }

    /**
     * Decode a base64url-encoded string.
     */
    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);

        return $decoded !== false ? $decoded : '';
    }

    /**
     * Encode a DER ASN.1 INTEGER.
     */
    private static function asn1Integer(string $data): string
    {
        return "\x02" . self::asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode a DER ASN.1 SEQUENCE.
     */
    private static function asn1Sequence(string $data): string
    {
        return "\x30" . self::asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode a DER ASN.1 BIT STRING (with zero unused-bits prefix).
     */
    private static function asn1BitString(string $data): string
    {
        $content = "\x00" . $data;

        return "\x03" . self::asn1Length(strlen($content)) . $content;
    }

    /**
     * Encode a DER ASN.1 length value.
     */
    private static function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = pack('C', $temp & 0xff) . $bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
