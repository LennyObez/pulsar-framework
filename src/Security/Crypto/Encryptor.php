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

use SodiumException;

use function strlen;

/**
 * Authenticated encryption using libsodium's secretbox (XSalsa20-Poly1305).
 *
 * Ciphertext format: nonce (24 bytes) || ciphertext+mac.
 */
final class Encryptor
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

    /**
     * Create an Encryptor with a raw key directly.
     *
     * @param string $key Raw 32-byte secretbox key
     */
    private readonly Randomizer $randomizer;

    private function __construct(
        private readonly string $key,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    /**
     * Create an Encryptor using the default subkey derivation (subKeyId=1, context='encrypt_').
     *
     * This is the standard construction path for general-purpose encryption.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromMasterKey(MasterKey $masterKey): self
    {
        return new self($masterKey->deriveSubKey(self::DEFAULT_SUB_KEY_ID, self::DEFAULT_KDF_CONTEXT));
    }

    /**
     * Create an Encryptor using a specific subkey derivation.
     *
     * Enables domain separation for subsystems that need their own
     * encryption keys (e.g., Studio uses subKeyId=3, context='studio_enc__').
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
    {
        return new self($masterKey->deriveSubKey($subKeyId, $context));
    }

    /**
     * Encrypt plaintext and return base64-encoded ciphertext.
     *
     * Output format: base64(nonce || ciphertext_with_mac)
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

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw SecurityException::decryptionFailed();
        }

        return $plaintext;
    }

    /**
     * Prevent key from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '[REDACTED]'];
    }
}
