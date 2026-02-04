<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Security\Exception\SecurityException;

use function sodium_bin2hex;
use function sodium_crypto_kdf_derive_from_key;
use function sodium_hex2bin;

use SodiumException;

use function sprintf;
use function strlen;

/**
 * Master key management backed by libsodium KDF.
 *
 * Loads the application master key from the `PULSAR_MASTER_KEY` environment variable
 * (hex-encoded 32 bytes) and derives purpose-specific subkeys using
 * `sodium_crypto_kdf_derive_from_key`.
 *
 * Sub-key IDs:
 * - 1 = encryption (used by Encryptor)
 * - 2 = audit HMAC chain
 */
final class MasterKey implements KeyProviderInterface
{
    /**
     * Expected key length in bytes.
     */
    private const int KEY_LENGTH = SODIUM_CRYPTO_KDF_KEYBYTES;

    /**
     * Context must be exactly 8 bytes for sodium KDF.
     */
    private const int CONTEXT_LENGTH = SODIUM_CRYPTO_KDF_CONTEXTBYTES;

    private readonly string $rawKey;

    private function __construct(string $rawKey)
    {
        $this->rawKey = $rawKey;
    }

    /**
     * Load master key from a hex-encoded string.
     *
     * @throws SecurityException If the key is invalid
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromHex(string $hex): self
    {
        $raw = sodium_hex2bin($hex);

        if (strlen($raw) !== self::KEY_LENGTH) {
            throw SecurityException::masterKeyInvalid(
                sprintf('expected %d bytes, got %d', self::KEY_LENGTH, strlen($raw)),
            );
        }

        return new self($raw);
    }

    /**
     * Load master key from the PULSAR_MASTER_KEY environment variable.
     *
     * @throws SecurityException If the variable is missing or invalid
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromEnvironment(?string $envValue = null): self
    {
        $hex = $envValue ?? getenv('PULSAR_MASTER_KEY');

        if ($hex === false || $hex === '') {
            throw SecurityException::masterKeyMissing();
        }

        return self::fromHex($hex);
    }

    /**
     * Derive a purpose-specific subkey.
     *
     * @param int    $subKeyId Non-negative integer identifying the subkey purpose
     * @param string $context  Exactly 8-byte ASCII context string (padded/truncated automatically)
     * @param int    $length   Desired subkey length in bytes (16–64)
     *
     * @return string Raw subkey bytes
     *
     * @throws SodiumException
     */
    public function deriveSubKey(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): string
    {
        $context = self::normalizeContext($context);

        return sodium_crypto_kdf_derive_from_key($length, $subKeyId, $context, $this->rawKey);
    }

    /**
     * Derive a subkey and return it as hex.
     *
     * @throws SodiumException
     */
    public function deriveSubKeyHex(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): string
    {
        return sodium_bin2hex($this->deriveSubKey($subKeyId, $context, $length));
    }

    /**
     * Prevent master key from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['rawKey' => '[REDACTED]'];
    }

    /**
     * Normalize context to exactly CONTEXT_LENGTH bytes.
     */
    private static function normalizeContext(string $context): string
    {
        if (strlen($context) >= self::CONTEXT_LENGTH) {
            return substr($context, 0, self::CONTEXT_LENGTH);
        }

        return str_pad($context, self::CONTEXT_LENGTH, '_');
    }
}
