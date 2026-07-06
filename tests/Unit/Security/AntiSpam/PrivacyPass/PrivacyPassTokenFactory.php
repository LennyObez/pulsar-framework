<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;
use RuntimeException;

use function base64_decode;
use function ceil;
use function chr;
use function gmp_export;
use function gmp_import;
use function gmp_powm;
use function hash;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function ord;
use function pack;
use function preg_replace;
use function random_bytes;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;

use const OPENSSL_KEYTYPE_RSA;
use const STR_PAD_LEFT;

/**
 * Mints valid Token Type 0x0002 (Blind RSA) tokens for tests, signing with a
 * controlled RSA key. This lets the bypass/wiring tests exercise the real
 * stateless (empty redemption_context) path that the single published RFC
 * vector — bound to a 32-byte context — cannot.
 *
 * The factory is itself validated by {@see PrivateAccessTokenVerifier}, which is
 * proven against the official RFC 9578 known-answer vector; a token the factory
 * mints must verify under that verifier, so the two cannot drift undetected.
 */
final class PrivacyPassTokenFactory
{
    /**
     * Issue a token bound to $challenge, signed with a fresh RSA-2048 key.
     *
     * @return array{spkiDer: string, token: string}
     */
    public static function issue(TokenChallenge $challenge): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($key === false) {
            throw new RuntimeException('Unable to generate RSA key for the test.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Unable to read RSA key details.');
        }

        /** @var array{key: string, rsa: array{n: string, d: string}} $details */
        $spkiDer = self::pemToDer($details['key']);
        $n = $details['rsa']['n'];
        $d = $details['rsa']['d'];

        $tokenKeyId = hash('sha256', $spkiDer, true);
        $nonce = random_bytes(32);
        $challengeDigest = $challenge->digest();

        $authenticatorInput = pack('n', 0x0002) . $nonce . $challengeDigest . $tokenKeyId;
        $authenticator = self::rsaSsaPssSign($authenticatorInput, $d, $n);

        $token = pack('n', 0x0002) . $nonce . $challengeDigest . $tokenKeyId . $authenticator;

        return ['spkiDer' => $spkiDer, 'token' => $token];
    }

    private static function rsaSsaPssSign(string $message, string $d, string $modulus): string
    {
        $k = strlen($modulus);
        $em = self::emsaPssEncode($message, 8 * $k - 1, 'sha384', 48);

        $sig = gmp_powm(gmp_import($em), gmp_import($d), gmp_import($modulus));

        return str_pad(gmp_export($sig), $k, "\x00", STR_PAD_LEFT);
    }

    /**
     * EMSA-PSS-ENCODE (RFC 8017 §9.1.1).
     *
     * @param positive-int $sLen
     */
    private static function emsaPssEncode(string $message, int $emBits, string $hash, int $sLen): string
    {
        $hLen = strlen(hash($hash, '', true));
        $emLen = (int) ceil($emBits / 8);

        $mHash = hash($hash, $message, true);
        $salt = random_bytes($sLen);
        $mPrime = "\x00\x00\x00\x00\x00\x00\x00\x00" . $mHash . $salt;
        $h = hash($hash, $mPrime, true);

        $ps = str_repeat("\x00", $emLen - $sLen - $hLen - 2);
        $db = $ps . "\x01" . $salt;
        $dbMask = self::mgf1($h, $emLen - $hLen - 1, $hash);
        $maskedDb = $db ^ $dbMask;

        $zeroBits = 8 * $emLen - $emBits;
        $maskedDb[0] = chr((ord($maskedDb[0]) & (0xFF >> $zeroBits)) & 0xFF);

        return $maskedDb . $h . "\xbc";
    }

    private static function mgf1(string $seed, int $length, string $hash): string
    {
        $output = '';
        $counter = 0;
        while (strlen($output) < $length) {
            $output .= hash($hash, $seed . pack('N', $counter), true);
            $counter++;
        }

        return substr($output, 0, $length);
    }

    private static function pemToDer(string $pem): string
    {
        $base64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

        return (string) base64_decode($base64, true);
    }
}
