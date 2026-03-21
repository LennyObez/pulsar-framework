<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use SodiumException;

use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function sodium_crypto_kdf_derive_from_key;
use function sodium_hex2bin;
use function sodium_memzero;
use function sprintf;
use function strlen;
use function substr;

/**
 * Master key management backed by libsodium KDF.
 *
 * Loads the application master key from the `PULSAR_MASTER_KEY` environment variable
 * (hex-encoded 32 bytes) and derives purpose-specific subkeys using
 * `sodium_crypto_kdf_derive_from_key`.
 *
 * Supports an optional previous key for key rotation. The previous key enables
 * fallback decryption and audit verification during rotation windows.
 *
 * Sub-key IDs:
 * - 1 = encryption (used by Encryptor)
 * - 2 = audit HMAC chain
 * - 3 = pseudonymization (used by PseudonymizationService)
 * @api
 */
#[Api(since: '1.0.0')]
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

    private string $rawKey;
    private ?string $previousRawKey;

    private function __construct(string $rawKey, ?string $previousRawKey = null)
    {
        $this->rawKey = $rawKey;
        $this->previousRawKey = $previousRawKey;
    }

    public function __destruct()
    {
        // Zero key material from memory. Use a local variable because
        // sodium_memzero() sets its argument to null by reference, which
        // conflicts with the string property type.
        $key = $this->rawKey;
        $this->rawKey = '';

        try {
            sodium_memzero($key);
        } catch (SodiumException) {
            // Best-effort zeroing: nothing to do if it fails
        }

        if ($this->previousRawKey !== null) {
            $prev = $this->previousRawKey;
            $this->previousRawKey = null;

            try {
                sodium_memzero($prev);
            } catch (SodiumException) {
                // Best-effort zeroing
            }
        }
    }

    /**
     * @return array<string, string>
     * @throws SecurityException
     */
    public function __serialize(): array
    {
        throw SecurityException::serializationForbidden('MasterKey');
    }

    /**
     * @param array<string, mixed> $data
     * @throws SecurityException
     */
    public function __unserialize(array $data): void
    {
        throw SecurityException::serializationForbidden('MasterKey');
    }

    /**
     * Load master key from a hex-encoded string.
     *
     * @throws SecurityException If the key is invalid
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromHex(string $hex, ?string $previousHex = null): self
    {
        $raw = sodium_hex2bin($hex);

        if (strlen($raw) !== self::KEY_LENGTH) {
            throw SecurityException::masterKeyInvalid(
                sprintf('expected %d bytes, got %d', self::KEY_LENGTH, strlen($raw)),
            );
        }

        $previousRaw = null;
        if ($previousHex !== null) {
            $previousRaw = sodium_hex2bin($previousHex);
            if (strlen($previousRaw) !== self::KEY_LENGTH) {
                throw SecurityException::masterKeyInvalid(
                    sprintf('previous key: expected %d bytes, got %d', self::KEY_LENGTH, strlen($previousRaw)),
                );
            }
        }

        return new self($raw, $previousRaw);
    }

    /**
     * Load master key from the PULSAR_MASTER_KEY environment variable.
     * Optionally reads PULSAR_MASTER_KEY_PREVIOUS for key rotation support.
     *
     * WARNING: In persistent worker processes (RoadRunner, FrankenPHP, Swoole)
     * and long-running PHP-FPM pools, `getenv()` reads from the process-level
     * environment which persists across requests. Pass the key explicitly via
     * `$envValue` parameter or use `fromHex()` with a secrets manager instead
     * of relying on `getenv()` in these contexts.
     *
     * F9.8: this method calls `getenv()` directly, bypassing
     * {@see Environment::loadFiltered()}'s allowlist. Production
     * deployments that hardened the environment loader should use
     * {@see fromConfigEnvironment()} instead so master-key access
     * goes through the same allowlist as every other secret.
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

        $previousHex = getenv('PULSAR_MASTER_KEY_PREVIOUS');
        if ($previousHex === false || $previousHex === '') {
            $previousHex = null;
        }

        return self::fromHex($hex, $previousHex);
    }

    /**
     * F9.8: load master key through a {@see Environment} instance so
     * the access goes through the same allowlist (and any other
     * filtering) the operator has wired. Preferred over
     * {@see fromEnvironment()} in production deployments because the
     * `getenv()` global pollution path is bypassed entirely.
     *
     * @throws SecurityException If the variable is missing or invalid
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromConfigEnvironment(\Pulsar\Config\Environment $env): self
    {
        $hex = $env->get('PULSAR_MASTER_KEY');

        if ($hex === null || $hex === '') {
            throw SecurityException::masterKeyMissing();
        }

        $previousHex = $env->get('PULSAR_MASTER_KEY_PREVIOUS');
        if ($previousHex === '') {
            $previousHex = null;
        }

        return self::fromHex($hex, $previousHex);
    }

    /**
     * Derive a purpose-specific subkey.
     *
     * @param int    $subKeyId Non-negative integer identifying the subkey purpose
     * @param string $context  Exactly 8-byte ASCII context string
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
     * Derive a subkey from the previous master key (for key rotation fallback).
     *
     * @return string|null Raw subkey bytes, or null if no previous key
     *
     * @throws SodiumException
     */
    public function derivePreviousSubKey(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): ?string
    {
        if ($this->previousRawKey === null) {
            return null;
        }

        $context = self::normalizeContext($context);

        return sodium_crypto_kdf_derive_from_key($length, $subKeyId, $context, $this->previousRawKey);
    }

    public function hasPreviousKey(): bool
    {
        return $this->previousRawKey !== null;
    }

    /**
     * Compute a 16-hex-char (64-bit) key identifier for a derived subkey.
     *
     * Uses the minimum generichash output (16 bytes) and takes the first 16 hex chars.
     *
     * @throws SodiumException
     */
    public function keyId(int $subKeyId, string $context): string
    {
        $derivedKey = $this->deriveSubKey($subKeyId, $context);
        $hash = sodium_crypto_generichash($derivedKey, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN);
        sodium_memzero($derivedKey);

        return substr(sodium_bin2hex($hash), 0, 16);
    }

    /**
     * Compute a key identifier for the previous master key's derived subkey.
     *
     * @throws SodiumException
     */
    public function previousKeyId(int $subKeyId, string $context): ?string
    {
        $derivedKey = $this->derivePreviousSubKey($subKeyId, $context);
        if ($derivedKey === null) {
            return null;
        }
        $hash = sodium_crypto_generichash($derivedKey, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN);
        sodium_memzero($derivedKey);

        return substr(sodium_bin2hex($hash), 0, 16);
    }

    /**
     * Prevent master key from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'rawKey' => '[REDACTED]',
            'previousRawKey' => $this->previousRawKey !== null ? '[REDACTED]' : '[NONE]',
        ];
    }

    /**
     * Validate that context is exactly CONTEXT_LENGTH bytes.
     *
     * @throws InvalidArgumentException If context length does not match
     */
    private static function normalizeContext(string $context): string
    {
        if (strlen($context) !== self::CONTEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'KDF context must be exactly %d bytes, got %d. Context: "%s"',
                self::CONTEXT_LENGTH,
                strlen($context),
                $context,
            ));
        }

        return $context;
    }
}
