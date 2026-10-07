<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass\Internal;

use InvalidArgumentException;
use Pulsar\Api\Internal;

use function base64_decode;
use function hash;
use function ltrim;
use function ord;
use function strlen;
use function strtr;
use function substr;

/**
 * An issuer's RSA public key, parsed from its DER-encoded SubjectPublicKeyInfo.
 *
 * Privacy Pass carries the issuer key as a base64url SPKI in the
 * `WWW-Authenticate: PrivateToken token-key` parameter (RFC 9577) using the
 * id-RSASSA-PSS algorithm identifier (RFC 9578 §8.2.2). This class extracts the
 * RSA modulus and exponent for verification and computes the token_key_id —
 * SHA-256 over the SPKI DER — which a redeemed token echoes so the Origin can
 * confirm it was signed under the expected key.
 *
 * A minimal DER walk is used rather than openssl_pkey_get_public(): the latter
 * may reject or mishandle RSASSA-PSS-restricted keys, and we only need n and e.
 */
#[Internal]
final readonly class IssuerPublicKey
{
    private function __construct(
        public string $spkiDer,
        public string $modulus,
        public string $exponent,
    ) {}

    /**
     * @throws InvalidArgumentException on malformed input
     */
    public static function fromSpkiDer(string $der): self
    {
        [$modulus, $exponent] = self::extractRsa($der);

        return new self($der, $modulus, $exponent);
    }

    /**
     * @throws InvalidArgumentException on malformed input
     */
    public static function fromBase64Url(string $value): self
    {
        $der = self::base64UrlDecode($value);
        if ($der === '') {
            throw new InvalidArgumentException('Empty or invalid base64url token key.');
        }

        return self::fromSpkiDer($der);
    }

    /**
     * token_key_id = SHA-256(SPKI DER) (RFC 9578 §6.5).
     */
    public function keyId(): string
    {
        return hash('sha256', $this->spkiDer, true);
    }

    /**
     * Walk the SPKI DER and return [modulus, exponent] as big-endian bytes.
     *
     * SubjectPublicKeyInfo ::= SEQUENCE { algorithm AlgorithmIdentifier,
     *   subjectPublicKey BIT STRING { RSAPublicKey ::= SEQUENCE { n INTEGER, e INTEGER } } }
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    private static function extractRsa(string $der): array
    {
        $pos = 0;
        self::expectSequence($der, $pos);            // SubjectPublicKeyInfo

        [, $algLen, $pos] = self::expectSequence($der, $pos); // AlgorithmIdentifier
        $pos += $algLen;                             // skip the algorithm identifier body

        [$bitTag, , $pos] = self::readTagLength($der, $pos); // subjectPublicKey BIT STRING
        if ($bitTag !== 0x03) {
            throw new InvalidArgumentException('Expected BIT STRING for subjectPublicKey.');
        }
        if (($der[$pos] ?? '') !== "\x00") {
            throw new InvalidArgumentException('Unexpected unused-bits count in BIT STRING.');
        }
        $pos++; // unused-bits octet

        self::expectSequence($der, $pos);            // RSAPublicKey
        $modulus = self::readInteger($der, $pos);
        $exponent = self::readInteger($der, $pos);

        return [ltrim($modulus, "\x00"), ltrim($exponent, "\x00")];
    }

    /**
     * Consume a SEQUENCE header at $pos, advancing past it.
     *
     * @return array{0: int, 1: int, 2: int} [tag, body-length, position-after-header]
     */
    private static function expectSequence(string $der, int &$pos): array
    {
        [$tag, $len, $newPos] = self::readTagLength($der, $pos);
        if ($tag !== 0x30) {
            throw new InvalidArgumentException('Expected DER SEQUENCE.');
        }
        $pos = $newPos;

        return [$tag, $len, $newPos];
    }

    private static function readInteger(string $der, int &$pos): string
    {
        [$tag, $len, $newPos] = self::readTagLength($der, $pos);
        if ($tag !== 0x02) {
            throw new InvalidArgumentException('Expected DER INTEGER.');
        }
        $value = substr($der, $newPos, $len);
        if (strlen($value) !== $len) {
            throw new InvalidArgumentException('Truncated DER INTEGER.');
        }
        $pos = $newPos + $len;

        return $value;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [tag, length, position-after-header]
     *
     * @throws InvalidArgumentException
     */
    private static function readTagLength(string $der, int $pos): array
    {
        if (!isset($der[$pos], $der[$pos + 1])) {
            throw new InvalidArgumentException('Truncated DER header.');
        }
        $tag = ord($der[$pos]);
        $pos++;
        $first = ord($der[$pos]);
        $pos++;

        if ($first < 0x80) {
            return [$tag, $first, $pos];
        }

        $numBytes = $first & 0x7F;
        if ($numBytes === 0 || $numBytes > 4) {
            throw new InvalidArgumentException('Unsupported DER length encoding.');
        }

        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            if (!isset($der[$pos])) {
                throw new InvalidArgumentException('Truncated DER length.');
            }
            $length = ($length << 8) | ord($der[$pos]);
            $pos++;
        }

        return [$tag, $length, $pos];
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
