<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass\Internal;

use Pulsar\Api\Internal;

use function ceil;
use function chr;
use function extension_loaded;
use function gmp_export;
use function gmp_import;
use function gmp_powm;
use function hash;
use function hash_equals;
use function ord;
use function pack;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;

use const STR_PAD_LEFT;

/**
 * RSASSA-PSS signature verification (RFC 8017) over the raw RSA primitive.
 *
 * PHP's openssl_verify() does not expose PSS padding, so verification is done
 * explicitly: recover the encoded message with the public RSA operation
 * (m = s^e mod n) using GMP, then run EMSA-PSS-VERIFY per RFC 8017 §9.1.2.
 * Used by the Privacy Pass token verifier for token type 0x0002, which fixes
 * the parameters to SHA-384, MGF1-SHA-384, and a 48-byte salt (RFC 9578 §8.2).
 *
 * Requires ext-gmp for the big-integer modular exponentiation.
 */
#[Internal]
final readonly class RsaSsaPssVerifier
{
    public function __construct(
        private string $hashAlgorithm = 'sha384',
        private int $saltLength = 48,
    ) {}

    public static function isSupported(): bool
    {
        return extension_loaded('gmp');
    }

    /**
     * Verify that $signature is a valid RSASSA-PSS signature over $message.
     *
     * @param string $message   the signed message (raw bytes)
     * @param string $signature the signature (raw bytes, length = modulus length)
     * @param string $modulus   RSA modulus n (big-endian, no sign byte)
     * @param string $exponent  RSA public exponent e (big-endian)
     */
    public function verify(string $message, string $signature, string $modulus, string $exponent): bool
    {
        if (!self::isSupported()) {
            return false;
        }

        $k = strlen($modulus);
        if ($k === 0 || strlen($signature) !== $k) {
            return false;
        }

        $n = gmp_import($modulus);
        $e = gmp_import($exponent);
        $s = gmp_import($signature);

        // Signature representative must be in [0, n-1].
        if ($s < 0 || $s >= $n) {
            return false;
        }

        $m = gmp_powm($s, $e, $n);

        // I2OSP(m, k): big-endian, left-padded to the modulus length.
        $em = str_pad(gmp_export($m), $k, "\x00", STR_PAD_LEFT);

        $modBits = $this->bitLength($modulus);

        return $this->emsaPssVerify($message, $em, $modBits - 1);
    }

    /**
     * EMSA-PSS-VERIFY (RFC 8017 §9.1.2).
     */
    private function emsaPssVerify(string $message, string $em, int $emBits): bool
    {
        $hLen = strlen(hash($this->hashAlgorithm, '', true));
        $sLen = $this->saltLength;
        $emLen = (int) ceil($emBits / 8);

        if ($emLen < $hLen + $sLen + 2) {
            return false;
        }

        if (substr($em, -1) !== "\xbc") {
            return false;
        }

        $maskedDb = substr($em, 0, $emLen - $hLen - 1);
        $h = substr($em, $emLen - $hLen - 1, $hLen);

        $zeroBits = 8 * $emLen - $emBits;
        if ($zeroBits > 0 && (ord($maskedDb[0]) >> (8 - $zeroBits)) !== 0) {
            return false;
        }

        $dbMask = $this->mgf1($h, $emLen - $hLen - 1);
        $db = $maskedDb ^ $dbMask;
        $db[0] = chr((ord($db[0]) & (0xFF >> $zeroBits)) & 0xFF);

        $psLen = $emLen - $hLen - $sLen - 2;
        if (substr($db, 0, $psLen) !== str_repeat("\x00", $psLen)) {
            return false;
        }

        if ($db[$psLen] !== "\x01") {
            return false;
        }

        $salt = $sLen > 0 ? substr($db, -$sLen) : '';

        $mHash = hash($this->hashAlgorithm, $message, true);
        $mPrime = "\x00\x00\x00\x00\x00\x00\x00\x00" . $mHash . $salt;
        $hPrime = hash($this->hashAlgorithm, $mPrime, true);

        return hash_equals($h, $hPrime);
    }

    /**
     * MGF1 mask generation function (RFC 8017 §B.2.1).
     */
    private function mgf1(string $seed, int $length): string
    {
        $output = '';
        $counter = 0;

        while (strlen($output) < $length) {
            $output .= hash($this->hashAlgorithm, $seed . pack('N', $counter), true);
            $counter++;
        }

        return substr($output, 0, $length);
    }

    private function bitLength(string $bytes): int
    {
        $len = strlen($bytes);
        $i = 0;
        while ($i < $len && $bytes[$i] === "\x00") {
            $i++;
        }
        if ($i === $len) {
            return 0;
        }

        $bits = ($len - $i - 1) * 8;
        $top = ord($bytes[$i]);
        while ($top > 0) {
            $bits++;
            $top >>= 1;
        }

        return $bits;
    }
}
