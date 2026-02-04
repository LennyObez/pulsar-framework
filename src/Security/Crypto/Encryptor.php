<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Security\Exception\SecurityException;

use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;

use SodiumException;

use function strlen;

/**
 * Authenticated encryption using libsodium's secretbox (XSalsa20-Poly1305).
 *
 * Uses a derived subkey from MasterKey (subKeyId=1, context='encrypt_').
 * Ciphertext format: nonce (24 bytes) || ciphertext+mac.
 */
final class Encryptor
{
    /**
     * Sub-key ID for encryption.
     */
    private const int SUB_KEY_ID = 1;

    /**
     * KDF context for encryption key derivation.
     */
    private const string KDF_CONTEXT = 'encrypt_';

    /**
     * Nonce length in bytes.
     */
    private const int NONCE_LENGTH = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private readonly string $key;

    public function __construct(MasterKey $masterKey)
    {
        $this->key = $masterKey->deriveSubKey(self::SUB_KEY_ID, self::KDF_CONTEXT);
    }

    /**
     * Encrypt plaintext and return base64-encoded ciphertext.
     *
     * Output format: base64(nonce || ciphertext_with_mac)
     *
     * @throws SecurityException If encryption fails
     * @throws \Random\RandomException
     * @throws SodiumException
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
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
