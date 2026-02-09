<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sodium_memzero;

use SodiumException;

use function strlen;

/**
 * Authenticated encryption using libsodium's secretbox (XSalsa20-Poly1305).
 *
 * Ciphertext format: nonce (24 bytes) || ciphertext+mac.
 *
 * Supports an optional previous key for transparent fallback decryption
 * during key rotation windows.
 */
final class Encryptor implements EncryptorInterface
{
    /**
     * Sub-key ID for the default encryption context.
     */
    private const int DEFAULT_SUB_KEY_ID = 1;

    /**
     * KDF context for the default encryption key derivation.
     */
    private const string DEFAULT_KDF_CONTEXT = 'encrypt_';

    /**
     * Nonce length in bytes.
     */
    private const int NONCE_LENGTH = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private readonly Randomizer $randomizer;

    private function __construct(
        private string $key,
        private ?string $previousKey = null,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function __destruct()
    {
        // Zero key material from memory. Use a local variable because
        // sodium_memzero() sets its argument to null by reference, which
        // conflicts with the string property type.
        $key = $this->key;
        $this->key = '';

        try {
            sodium_memzero($key);
        } catch (SodiumException) {
            // Best-effort zeroing
        }

        if ($this->previousKey !== null) {
            $prev = $this->previousKey;
            $this->previousKey = null;

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
        throw SecurityException::serializationForbidden('Encryptor');
    }

    /**
     * @param array<string, mixed> $data
     * @throws SecurityException
     */
    public function __unserialize(array $data): void
    {
        throw SecurityException::serializationForbidden('Encryptor');
    }

    /**
     * Create an Encryptor using the default subkey derivation (subKeyId=1, context='encrypt_').
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromMasterKey(MasterKey $masterKey): self
    {
        $key = $masterKey->deriveSubKey(self::DEFAULT_SUB_KEY_ID, self::DEFAULT_KDF_CONTEXT);

        $previousKey = null;
        if ($masterKey->hasPreviousKey()) {
            $previousKey = $masterKey->derivePreviousSubKey(self::DEFAULT_SUB_KEY_ID, self::DEFAULT_KDF_CONTEXT);
        }

        return new self($key, $previousKey);
    }

    /**
     * Create an Encryptor using a specific subkey derivation.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
    {
        $key = $masterKey->deriveSubKey($subKeyId, $context);

        $previousKey = null;
        if ($masterKey->hasPreviousKey()) {
            $previousKey = $masterKey->derivePreviousSubKey($subKeyId, $context);
        }

        return new self($key, $previousKey);
    }

    /**
     * Encrypt plaintext and return base64-encoded ciphertext.
     *
     * Output format: base64(nonce || ciphertext_with_mac)
     * Always encrypts with the current key.
     *
     * @throws SecurityException If encryption fails
     * @throws RandomException
     * @throws SodiumException
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = $this->randomizer->getBytes(self::NONCE_LENGTH);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a base64-encoded ciphertext produced by encrypt().
     *
     * Tries the current key first, then falls back to the previous key
     * if available. Throws the original exception if both fail.
     *
     * @throws SecurityException If decryption fails (wrong key, tampered data, etc.)
     * @throws SodiumException
     */
    public function decrypt(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw SecurityException::decryptionFailed();
        }

        if (strlen($decoded) < self::NONCE_LENGTH + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw SecurityException::decryptionFailed();
        }

        $nonce = substr($decoded, 0, self::NONCE_LENGTH);
        $ciphertext = substr($decoded, self::NONCE_LENGTH);

        // Try current key
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext !== false) {
            return $plaintext;
        }

        // Fallback to previous key if available
        if ($this->previousKey !== null) {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->previousKey);
            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw SecurityException::decryptionFailed();
    }

    /**
     * Create an encryptor using a specific subkey derivation.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function withDerivedKey(KeyProviderInterface $masterKey, int $subKeyId, string $context): self
    {
        $key = $masterKey->deriveSubKey($subKeyId, $context);

        $previousKey = null;
        if ($masterKey instanceof MasterKey && $masterKey->hasPreviousKey()) {
            $previousKey = $masterKey->derivePreviousSubKey($subKeyId, $context);
        }

        return new self($key, $previousKey);
    }

    /**
     * Prevent key from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'key' => '[REDACTED]',
            'previousKey' => $this->previousKey !== null ? '[REDACTED]' : '[NONE]',
        ];
    }
}
