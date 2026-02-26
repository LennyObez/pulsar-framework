<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use SodiumException;

use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sodium_memzero;
use function strlen;

/**
 * Authenticated encryption using libsodium's secretbox (XSalsa20-Poly1305).
 *
 * Ciphertext format: nonce (24 bytes) || ciphertext+mac.
 *
 * Supports an optional previous key for transparent fallback decryption
 * during key rotation windows.
 */
#[Api(since: '1.0.0')]
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
        private readonly ?CipherSuiteInterface $cipherSuite = null,
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
    public static function fromMasterKey(MasterKey $masterKey, ?CipherSuiteInterface $cipherSuite = null): self
    {
        $key = $masterKey->deriveSubKey(self::DEFAULT_SUB_KEY_ID, self::DEFAULT_KDF_CONTEXT);

        $previousKey = null;
        if ($masterKey->hasPreviousKey()) {
            $previousKey = $masterKey->derivePreviousSubKey(self::DEFAULT_SUB_KEY_ID, self::DEFAULT_KDF_CONTEXT);
        }

        return new self($key, $previousKey, $cipherSuite);
    }

    /**
     * Create an Encryptor using a specific subkey derivation.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromDerivedKey(MasterKey $masterKey, int $subKeyId, string $context, ?CipherSuiteInterface $cipherSuite = null): self
    {
        $key = $masterKey->deriveSubKey($subKeyId, $context);

        $previousKey = null;
        if ($masterKey->hasPreviousKey()) {
            $previousKey = $masterKey->derivePreviousSubKey($subKeyId, $context);
        }

        return new self($key, $previousKey, $cipherSuite);
    }

    /**
     * Encrypt plaintext and return base64-encoded ciphertext.
     *
     * When a cipher suite is configured, delegates to it. Otherwise uses
     * the legacy sodium_crypto_secretbox path for backward compatibility.
     *
     * Output format: base64(nonce || ciphertext_with_mac) [legacy]
     *                base64(version || nonce || ciphertext_with_mac) [cipher suite]
     *
     * Always encrypts with the current key.
     *
     * @throws SecurityException If encryption fails
     * @throws RandomException
     * @throws SodiumException
     */
    public function encrypt(string $plaintext): string
    {
        if ($this->cipherSuite !== null) {
            $raw = $this->cipherSuite->encrypt($plaintext, $this->key);

            return base64_encode($raw);
        }

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

        if ($this->cipherSuite !== null) {
            return $this->decryptWithCipherSuite($decoded);
        }

        return $this->decryptLegacy($decoded);
    }

    /**
     * Create an encryptor using a specific subkey derivation.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
    {
        $key = $masterKey->deriveSubKey($subKeyId, $context);

        $previousKey = $masterKey->hasPreviousKey()
            ? $masterKey->derivePreviousSubKey($subKeyId, $context)
            : null;

        return new self($key, $previousKey, $this->cipherSuite);
    }

    /**
     * Return the configured cipher suite, if any.
     */
    #[NoDiscard]
    public function cipherSuite(): ?CipherSuiteInterface
    {
        return $this->cipherSuite;
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
            'cipherSuite' => $this->cipherSuite?->name() ?? 'legacy-sodium',
        ];
    }

    /**
     * Decrypt using the cipher suite with key rotation fallback.
     *
     * @throws SecurityException
     */
    private function decryptWithCipherSuite(string $decoded): string
    {
        /** @var CipherSuiteInterface $suite (non-null guaranteed by caller) */
        $suite = $this->cipherSuite;

        try {
            return $suite->decrypt($decoded, $this->key);
        } catch (SecurityException) {
            // Try previous key
        }

        if ($this->previousKey !== null) {
            try {
                return $suite->decrypt($decoded, $this->previousKey);
            } catch (SecurityException) {
                // Both keys failed
            }
        }

        throw SecurityException::decryptionFailed();
    }

    /**
     * Decrypt using the legacy sodium_crypto_secretbox path.
     *
     * @throws SecurityException
     * @throws SodiumException
     */
    private function decryptLegacy(string $decoded): string
    {
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
}
