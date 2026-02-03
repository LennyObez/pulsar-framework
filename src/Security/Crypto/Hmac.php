<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use function hash_equals;

use InvalidArgumentException;
use NoDiscard;

use function sodium_bin2hex;
use function sodium_crypto_generichash;

use SodiumException;

use function sprintf;
use function strlen;

/**
 * HMAC wrapper using libsodium's BLAKE2b (sodium_crypto_generichash).
 *
 * Provides keyed hashing for integrity verification and tamper detection.
 */
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

    private function __construct() {}

    /**
     * Compute a keyed BLAKE2b hash and return as hex string.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function computeHex(string $message, string $key): string
    {
        self::validateKey($key);

        $raw = sodium_crypto_generichash($message, $key, self::HASH_LENGTH);

        return sodium_bin2hex($raw);
    }

    /**
     * Compute a keyed BLAKE2b hash and return raw bytes.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function compute(string $message, string $key): string
    {
        self::validateKey($key);

        return sodium_crypto_generichash($message, $key, self::HASH_LENGTH);
    }

    /**
     * Verify a hex-encoded HMAC against a message and key.
     *
     * Uses constant-time comparison to prevent timing attacks.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function verifyHex(string $message, string $expectedHex, string $key): bool
    {
        $computedHex = self::computeHex($message, $key);

        return hash_equals($expectedHex, $computedHex);
    }

    /**
     * Verify raw HMAC bytes against a message and key.
     *
     * Uses constant-time comparison to prevent timing attacks.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function verify(string $message, string $expected, string $key): bool
    {
        $computed = self::compute($message, $key);

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
