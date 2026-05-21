<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use SodiumException;

use function hash_equals;
use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function sprintf;
use function strlen;

/**
 * HMAC wrapper using libsodium's BLAKE2b (sodium_crypto_generichash).
 *
 * Provides keyed hashing for integrity verification and tamper detection.
 * Supports an optional CipherSuiteInterface for pluggable MAC algorithms.
 * @api
 */
#[Api(since: '1.0.0')]
final class Hmac
{
    /**
     * Minimum key length in bytes for keyed hashing.
     */
    private const int MIN_KEY_LENGTH = SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN;

    /**
     * Output length in bytes (32 bytes = 256 bits).
     */
    private const int HASH_LENGTH = SODIUM_CRYPTO_GENERICHASH_BYTES;

    /**
     * Compute a keyed BLAKE2b hash and return as hex string.
     *
     * When a cipher suite is provided, delegates to its hmacHex method.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function computeHex(string $message, string $key, ?CipherSuiteInterface $cipherSuite = null): string
    {
        if ($cipherSuite !== null) {
            return $cipherSuite->hmacHex($message, $key);
        }

        self::validateKey($key);

        $raw = sodium_crypto_generichash($message, $key, self::HASH_LENGTH);

        return sodium_bin2hex($raw);
    }

    /**
     * Compute a keyed BLAKE2b hash and return raw bytes.
     *
     * When a cipher suite is provided, delegates to its hmac method.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function compute(string $message, string $key, ?CipherSuiteInterface $cipherSuite = null): string
    {
        if ($cipherSuite !== null) {
            return $cipherSuite->hmac($message, $key);
        }

        self::validateKey($key);

        return sodium_crypto_generichash($message, $key, self::HASH_LENGTH);
    }

    /**
     * Verify a hex-encoded HMAC against a message and key.
     *
     * Uses constant-time comparison to prevent timing attacks.
     * When a cipher suite is provided, uses its hmacHex for computation.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function verifyHex(string $message, string $expectedHex, string $key, ?CipherSuiteInterface $cipherSuite = null): bool
    {
        $computedHex = self::computeHex($message, $key, $cipherSuite);

        return hash_equals($expectedHex, $computedHex);
    }

    /**
     * Verify raw HMAC bytes against a message and key.
     *
     * Uses constant-time comparison to prevent timing attacks.
     * When a cipher suite is provided, uses its hmac for computation.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function verify(string $message, string $expected, string $key, ?CipherSuiteInterface $cipherSuite = null): bool
    {
        $computed = self::compute($message, $key, $cipherSuite);

        return hash_equals($expected, $computed);
    }

    /**
     * Validate that the key meets minimum length requirements.
     */
    private static function validateKey(string $key): void
    {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'HMAC key must be at least %d bytes, got %d',
                    self::MIN_KEY_LENGTH,
                    strlen($key),
                ),
            );
        }
    }
}
